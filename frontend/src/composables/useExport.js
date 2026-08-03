import { jsPDF } from 'jspdf'
import html2canvas from 'html2canvas'
import * as XLSX from 'xlsx'
import { fmtDate, isDateColumn } from '@/utils/date'

// ── helpers ─────────────────────────────────────────────────────────────────

function getColValue(col, row) {
  const raw = typeof col.field === 'function' ? col.field(row) ?? '' : row[col.field ?? col.name] ?? ''
  // Auto-format date columns so exports show "20 Jun 2026" not raw ISO strings
  if (isDateColumn(col.name) && raw) return fmtDate(raw)
  return raw
}

function prepareColumns(columns) {
  return columns.filter(c => c.name !== 'actions' && c.name !== 'created_at')
}

function titleize(s) {
  return String(s || '').replace(/-/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
}

function escapeHtml(v) {
  return String(v ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

/**
 * Branding pulled from what the app already caches at login. Falls back to the
 * company name/logo defaults so an export never renders blank.
 */
function meta() {
  let company = 'Afghan China Shopping Center'
  let tagline = 'Construction & Road Building'
  let logo = ''
  try {
    const c = JSON.parse(localStorage.getItem('company') || '{}')
    if (c && typeof c === 'object') {
      company = c.name || company
      tagline = c.tagline || c.industry || tagline
      logo = c.logo || c.logo_url || ''
    }
  } catch {
    /* ignore malformed cache */
  }
  logo = logo || localStorage.getItem('company_logo') || ''
  const rtl = (document.documentElement.dir || '').toLowerCase() === 'rtl'
  return { company, tagline, logo, rtl, date: fmtDate(new Date().toISOString()) }
}

/** Logo image if we have one, otherwise an inline SVG "building" emblem. */
function emblem(m) {
  if (m.logo) {
    return `<img src="${escapeHtml(m.logo)}" alt="" style="width:54px;height:54px;object-fit:contain;border-radius:10px;background:#fff" crossorigin="anonymous">`
  }
  return `<span style="display:inline-flex;align-items:center;justify-content:center;width:54px;height:54px;border-radius:12px;background:rgba(255,255,255,.16)">
    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-4h6v4M9 9h.01M15 9h.01M9 13h.01M15 13h.01" stroke="#fff" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
    </svg></span>`
}

/**
 * Branded, print-quality HTML for a report. The browser shapes Persian/Farsi
 * with the app fonts here — so rasterizing this (html2canvas) yields a PDF with
 * correct Persian glyphs, which jsPDF's built-in fonts can't render.
 */
function reportHtml(title, cols, data, m) {
  const dir = m.rtl ? 'rtl' : 'ltr'
  const align = m.rtl ? 'right' : 'left'
  const head = cols
    .map(c => `<th style="text-align:${align}">${escapeHtml(c.label || c.name)}</th>`)
    .join('')
  const body = data
    .map(
      (row, i) =>
        `<tr style="background:${i % 2 ? '#f4f8fd' : '#ffffff'}">` +
        cols.map(col => `<td style="text-align:${align}">${escapeHtml(getColValue(col, row))}</td>`).join('') +
        '</tr>'
    )
    .join('')

  return `<div dir="${dir}" style="width:1100px;box-sizing:border-box;padding:0;margin:0;background:#fff;
      font-family:'afg_sans','Vazirmatn','Poppins','Segoe UI',Tahoma,Arial,sans-serif;color:#1f2937">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;
        background:linear-gradient(135deg,#123A66 0%,#1c5a9e 100%);color:#fff;padding:22px 28px">
      <div style="display:flex;align-items:center;gap:16px">
        ${emblem(m)}
        <div>
          <div style="font-size:22px;font-weight:800;letter-spacing:.3px">${escapeHtml(m.company)}</div>
          <div style="font-size:12px;opacity:.85;margin-top:2px">${escapeHtml(m.tagline)}</div>
        </div>
      </div>
      <div style="text-align:${m.rtl ? 'left' : 'right'}">
        <div style="font-size:16px;font-weight:700">${escapeHtml(title)}</div>
        <div style="font-size:12px;opacity:.85;margin-top:2px">${escapeHtml(m.date)}</div>
        <div style="font-size:11px;opacity:.7;margin-top:2px">${data.length} ${m.rtl ? 'ردیف' : 'records'}</div>
      </div>
    </div>
    <div style="padding:20px 28px 26px">
      <table style="border-collapse:collapse;width:100%;font-size:12.5px">
        <thead><tr style="background:#123A66;color:#fff">${head}</tr></thead>
        <tbody>${body}</tbody>
      </table>
      <div style="margin-top:16px;font-size:10.5px;color:#94a3b8;text-align:${align}">
        ${escapeHtml(m.company)} · ${escapeHtml(m.date)}
      </div>
    </div>
  </div>`
    .replace(
      /<th style="/g,
      '<th style="padding:9px 12px;border-bottom:2px solid #0d2c4f;font-weight:700;white-space:nowrap;'
    )
    .replace(/<td style="/g, '<td style="padding:8px 12px;border-bottom:1px solid #e5edf6;')
}

/** Render HTML off-screen and rasterize it. Returns a <canvas>. */
async function renderCanvas(html) {
  const holder = document.createElement('div')
  holder.style.cssText = 'position:fixed;left:-10000px;top:0;z-index:-1;background:#fff'
  holder.innerHTML = html
  document.body.appendChild(holder)
  try {
    // Give webfonts a tick to apply so Persian shaping is correct.
    if (document.fonts?.ready) await document.fonts.ready
    return await html2canvas(holder.firstElementChild, {
      scale: 2,
      backgroundColor: '#ffffff',
      useCORS: true,
      logging: false,
    })
  } finally {
    holder.remove()
  }
}

// ── exports ─────────────────────────────────────────────────────────────────

/**
 * PDF export via branded-HTML rasterization. This is the Persian-safe path:
 * the browser shapes RTL/Farsi text, we snapshot it, and slice across A4 pages.
 */
export async function exportPdf(data, columns, filename = 'export', title = '') {
  const cols = prepareColumns(columns)
  const m = meta()
  const heading = title || titleize(filename)
  const canvas = await renderCanvas(reportHtml(heading, cols, data, m))

  const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' })
  const pageW = doc.internal.pageSize.getWidth()
  const pageH = doc.internal.pageSize.getHeight()
  const imgW = pageW
  const imgH = (canvas.height * imgW) / canvas.width

  if (imgH <= pageH) {
    doc.addImage(canvas.toDataURL('image/jpeg', 0.92), 'JPEG', 0, 0, imgW, imgH)
  } else {
    // Slice the tall canvas into page-height bands.
    const pxPerPage = Math.floor((canvas.width * pageH) / pageW)
    let y = 0
    let first = true
    while (y < canvas.height) {
      const sliceH = Math.min(pxPerPage, canvas.height - y)
      const slice = document.createElement('canvas')
      slice.width = canvas.width
      slice.height = sliceH
      slice.getContext('2d').drawImage(canvas, 0, y, canvas.width, sliceH, 0, 0, canvas.width, sliceH)
      if (!first) doc.addPage()
      doc.addImage(slice.toDataURL('image/jpeg', 0.92), 'JPEG', 0, 0, imgW, (sliceH * imgW) / canvas.width)
      first = false
      y += sliceH
    }
  }
  doc.save(`${filename}.pdf`)
}

export function exportExcel(data, columns, filename = 'export') {
  const cols = prepareColumns(columns)
  const header = cols.map(c => c.label || c.name)
  const rows = data.map(row => cols.map(col => getColValue(col, row)))

  const ws = XLSX.utils.aoa_to_sheet([header, ...rows])
  // Bold header row
  const range = XLSX.utils.decode_range(ws['!ref'])
  for (let C = range.s.c; C <= range.e.c; C++) {
    const cell = ws[XLSX.utils.encode_cell({ r: 0, c: C })]
    if (cell) cell.s = { font: { bold: true } }
  }
  const wb = XLSX.utils.book_new()
  XLSX.utils.book_append_sheet(wb, ws, 'Sheet1')
  XLSX.writeFile(wb, `${filename}.xlsx`)
}

export function exportWord(data, columns, filename = 'export', title = '') {
  const cols = prepareColumns(columns)
  const m = meta()
  const heading = title || titleize(filename)
  const dir = m.rtl ? 'rtl' : 'ltr'
  const align = m.rtl ? 'right' : 'left'

  const logoImg = m.logo
    ? `<img src="${escapeHtml(m.logo)}" style="width:52px;height:52px;object-fit:contain" />`
    : ''
  const head = cols
    .map(
      c =>
        `<th style="border:1px solid #0d2c4f;padding:7px;background:#123A66;color:#fff;text-align:${align}">${escapeHtml(c.label || c.name)}</th>`
    )
    .join('')
  const body = data
    .map(
      row =>
        '<tr>' +
        cols
          .map(col => `<td style="border:1px solid #ccc;padding:6px;text-align:${align}">${escapeHtml(getColValue(col, row))}</td>`)
          .join('') +
        '</tr>'
    )
    .join('')

  const html = `<!DOCTYPE html><html dir="${dir}"><head><meta charset="utf-8"><title>${escapeHtml(heading)}</title></head>
    <body style="font-family:'Segoe UI',Tahoma,Arial,sans-serif;color:#1f2937">
      <table style="width:100%;border-collapse:collapse;margin-bottom:14px">
        <tr>
          <td style="vertical-align:middle">${logoImg}</td>
          <td style="vertical-align:middle;padding:0 12px">
            <div style="font-size:20px;font-weight:bold;color:#123A66">${escapeHtml(m.company)}</div>
            <div style="font-size:12px;color:#64748b">${escapeHtml(m.tagline)}</div>
          </td>
          <td style="vertical-align:middle;text-align:${m.rtl ? 'left' : 'right'}">
            <div style="font-size:15px;font-weight:bold">${escapeHtml(heading)}</div>
            <div style="font-size:12px;color:#64748b">${escapeHtml(m.date)}</div>
          </td>
        </tr>
      </table>
      <table style="border-collapse:collapse;font-size:12px;width:100%">
        <thead><tr>${head}</tr></thead><tbody>${body}</tbody>
      </table>
      <p style="font-size:10px;color:#94a3b8;margin-top:12px">${escapeHtml(m.company)} · ${escapeHtml(m.date)}</p>
    </body></html>`

  const blob = new Blob(['﻿', html], { type: 'application/msword' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `${filename}.doc`
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}

export function whatsappShare(data, columns, filename = 'data') {
  const cols = prepareColumns(columns).slice(0, 4)
  const lines = data.slice(0, 20).map(row =>
    cols.map(col => `${col.label || col.name}: ${getColValue(col, row)}`).join(' | ')
  )
  const header = `*${titleize(filename)}*\n`
  const text = header + lines.join('\n') + (data.length > 20 ? `\n... and ${data.length - 20} more` : '')
  window.open(`https://wa.me/?text=${encodeURIComponent(text)}`, '_blank')
}
