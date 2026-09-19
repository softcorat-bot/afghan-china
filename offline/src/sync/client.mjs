/**
 * Talking to the central API.
 *
 * Every failure is classified, because the till must be able to say *which*
 * problem it has: no network at all, a network with an unreachable server, or a
 * server that answered "your device is not welcome". Only the last one needs a
 * human, and none of them may stop a sale.
 */
export class SyncError extends Error {
  constructor(message, { code = 'server_unavailable', status = null } = {}) {
    super(message);
    this.name = 'SyncError';
    this.code = code;
    this.status = status;
  }
}

const NETWORK_CODES = new Set(['ENOTFOUND', 'EAI_AGAIN', 'ECONNREFUSED', 'EHOSTUNREACH', 'ENETUNREACH', 'ENETDOWN', 'EHOSTDOWN']);

export class SyncClient {
  constructor({ baseUrl, deviceId, token, timeoutMs = 20000, fetchImpl = globalThis.fetch, appVersion = '1.0.0' }) {
    this.baseUrl = String(baseUrl).replace(/\/+$/, '');
    this.deviceId = deviceId;
    this.token = token;
    this.timeoutMs = timeoutMs;
    this.fetch = fetchImpl;
    this.appVersion = appVersion;
  }

  async status() {
    return this.request('GET', '/api/v1/sync/status');
  }

  async heartbeat(payload = {}) {
    return this.request('POST', '/api/v1/sync/heartbeat', payload);
  }

  async push({ batch_uuid, changes, appVersion }) {
    return this.request('POST', '/api/v1/sync/push', {
      batch_uuid,
      changes,
      app_version: appVersion ?? this.appVersion,
    });
  }

  async pull({ sinceSeq = 0, limit = 500, tables = null }) {
    const query = new URLSearchParams({ since_seq: String(sinceSeq), limit: String(limit) });
    if (tables?.length) query.set('tables', tables.join(','));

    return this.request('GET', `/api/v1/sync/pull?${query.toString()}`);
  }

  async ack({ cursor, applied = [], failed = [] }) {
    return this.request('POST', '/api/v1/sync/ack', {
      cursor,
      applied,
      failed,
      app_version: this.appVersion,
    });
  }

  async conflicts({ pendingOnly = false } = {}) {
    const query = new URLSearchParams({ limit: '100' });
    if (pendingOnly) query.set('pending_only', '1');

    return this.request('GET', `/api/v1/sync/conflicts?${query.toString()}`);
  }

  async request(method, path, body = null) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeoutMs);

    let response;
    try {
      response = await this.fetch(`${this.baseUrl}${path}`, {
        method,
        headers: {
          accept: 'application/json',
          ...(body ? { 'content-type': 'application/json' } : {}),
          'x-device-id': this.deviceId,
          ...(this.token ? { authorization: `Bearer ${this.token}` } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
        signal: controller.signal,
      });
    } catch (error) {
      clearTimeout(timer);

      if (error.name === 'AbortError') {
        throw new SyncError('The server did not answer in time.', { code: 'server_unavailable' });
      }

      const cause = error.cause?.code ?? error.code;
      if (NETWORK_CODES.has(cause)) {
        throw new SyncError('No internet connection.', { code: 'network_unavailable' });
      }

      throw new SyncError(`Could not reach the server: ${error.message}`, { code: 'server_unavailable' });
    } finally {
      clearTimeout(timer);
    }

    const text = await response.text();
    let payload = null;
    try { payload = text ? JSON.parse(text) : null; } catch { payload = { raw: text }; }

    if (response.status === 401) {
      throw new SyncError(payload?.message ?? 'This till is not authorized any more.', {
        code: payload?.code ?? 'device_token_invalid',
        status: 401,
      });
    }

    if (response.status === 403) {
      throw new SyncError(payload?.message ?? 'This till has been disabled.', {
        code: payload?.code ?? 'device_blocked',
        status: 403,
      });
    }

    if (response.status >= 500) {
      throw new SyncError(payload?.message ?? 'The server is having trouble.', { code: 'server_unavailable', status: response.status });
    }

    if (!response.ok) {
      throw new SyncError(payload?.message ?? `The server refused the request (${response.status}).`, {
        code: 'request_rejected',
        status: response.status,
        payload,
      });
    }

    return payload;
  }
}

/** Register an installation with the activation code an administrator issued. */
export async function registerDevice({ baseUrl, deviceId, activationCode, name, platform = 'windows', appVersion, osVersion, fetchImpl }) {
  const client = new SyncClient({ baseUrl, deviceId, token: null, fetchImpl, appVersion });

  try {
    return await client.request('POST', '/api/v1/sync/register', {
      device_id: deviceId,
      activation_code: activationCode,
      name,
      platform,
      os_version: osVersion,
      app_version: appVersion,
    });
  } catch (error) {
    if (error.code === 'request_rejected' && error.payload?.message) {
      throw new SyncError(error.payload.message, { code: 'registration_refused', status: error.status });
    }

    throw error;
  }
}
