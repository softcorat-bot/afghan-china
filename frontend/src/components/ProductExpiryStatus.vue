<template>
  <div v-if="product.track_expiry" class="expiry-status">
    <!-- Simple badge for product list -->
    <span v-if="mode === 'badge'" class="expiry-badge" :class="expiryClass">
      <span v-if="isExpired" class="badge-icon">🚨</span>
      <span v-else-if="isExpiring" class="badge-icon">⏰</span>
      <span v-else class="badge-icon">✓</span>
      {{ statusLabel }}
    </span>

    <!-- Detailed card for modals/dashboards -->
    <div v-else-if="mode === 'card'" class="expiry-card" :class="expiryClass">
      <div class="expiry-header">
        <h3>Expiry Information</h3>
        <span class="status-badge" :class="statusClass">{{ statusLabel }}</span>
      </div>

      <div class="expiry-details">
        <div class="detail-row">
          <label>Manufacture Date:</label>
          <span>{{ product.manufacture_date | dateFormat }}</span>
        </div>

        <div class="detail-row">
          <label>Expiry Date:</label>
          <span class="expiry-date">{{ product.expiry_date | dateFormat }}</span>
        </div>

        <div class="detail-row" v-if="product.shelf_life_days">
          <label>Shelf Life:</label>
          <span>{{ product.shelf_life_days }} days</span>
        </div>

        <div class="detail-row" v-if="daysLeft !== null">
          <label>Days Until Expiry:</label>
          <span :class="{ 'expired': isExpired, 'expiring': isExpiring }">
            {{ daysLeft < 0 ? `Expired ${Math.abs(daysLeft)} days ago` : `${daysLeft} days left` }}
          </span>
        </div>

        <div class="detail-row" v-if="batchesExpiring > 0">
          <label>Batches Expiring Soon:</label>
          <span class="warning">{{ batchesExpiring }} batches</span>
        </div>

        <div class="detail-row" v-if="batchesExpired > 0">
          <label>Expired Batches:</label>
          <span class="danger">{{ batchesExpired }} batches</span>
        </div>
      </div>

      <div class="expiry-progress" v-if="shelfLife > 0">
        <div class="progress-label">Stock Age</div>
        <div class="progress-bar">
          <div class="progress-fill" :style="{ width: progressPercent + '%' }"></div>
        </div>
        <div class="progress-percent">{{ Math.round(progressPercent) }}%</div>
      </div>
    </div>

    <!-- Table cell format -->
    <div v-else-if="mode === 'cell'" class="expiry-cell">
      <div class="cell-date">{{ product.expiry_date | dateFormat }}</div>
      <div class="cell-status" :class="expiryClass">{{ statusLabel }}</div>
    </div>
  </div>
</template>

<script>
import { computed } from 'vue'

export default {
  name: 'ProductExpiryStatus',
  props: {
    product: {
      type: Object,
      required: true
    },
    batchesExpiring: {
      type: Number,
      default: 0
    },
    batchesExpired: {
      type: Number,
      default: 0
    },
    mode: {
      type: String,
      default: 'badge',
      validator: v => ['badge', 'card', 'cell'].includes(v)
    }
  },
  setup(props) {
    const today = new Date()

    const expiryDate = computed(() => {
      if (!props.product.expiry_date) return null
      return new Date(props.product.expiry_date)
    })

    const manufactureDate = computed(() => {
      if (!props.product.manufacture_date) return null
      return new Date(props.product.manufacture_date)
    })

    const daysLeft = computed(() => {
      if (!expiryDate.value) return null
      const diffTime = expiryDate.value - today
      return Math.ceil(diffTime / (1000 * 60 * 60 * 24))
    })

    const isExpired = computed(() => {
      return daysLeft.value !== null && daysLeft.value < 0
    })

    const isExpiring = computed(() => {
      return daysLeft.value !== null && daysLeft.value >= 0 && daysLeft.value <= 30
    })

    const shelfLife = computed(() => {
      if (!manufactureDate.value || !expiryDate.value) return 0
      const diffTime = expiryDate.value - manufactureDate.value
      return Math.ceil(diffTime / (1000 * 60 * 60 * 24))
    })

    const age = computed(() => {
      if (!manufactureDate.value) return 0
      const diffTime = today - manufactureDate.value
      return Math.ceil(diffTime / (1000 * 60 * 60 * 24))
    })

    const progressPercent = computed(() => {
      if (shelfLife.value <= 0) return 0
      return (age.value / shelfLife.value) * 100
    })

    const statusLabel = computed(() => {
      if (!daysLeft.value) return 'No expiry date'
      if (isExpired.value) return 'EXPIRED'
      if (isExpiring.value) return `${daysLeft.value}d left`
      return 'Fresh'
    })

    const expiryClass = computed(() => {
      if (isExpired.value) return 'status-expired'
      if (isExpiring.value) return 'status-expiring'
      return 'status-fresh'
    })

    const statusClass = computed(() => {
      if (isExpired.value) return 'badge-danger'
      if (isExpiring.value) return 'badge-warning'
      return 'badge-success'
    })

    return {
      daysLeft,
      isExpired,
      isExpiring,
      statusLabel,
      expiryClass,
      statusClass,
      shelfLife,
      progressPercent
    }
  }
}
</script>

<style scoped>
.expiry-status {
  display: inline-block;
}

/* Badge Style */
.expiry-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}

.expiry-badge.status-expired {
  background: #ffe6e6;
  color: #d32f2f;
  border: 1px solid #ff9999;
}

.expiry-badge.status-expiring {
  background: #fff3e0;
  color: #f57c00;
  border: 1px solid #ffb74d;
}

.expiry-badge.status-fresh {
  background: #e8f5e9;
  color: #388e3c;
  border: 1px solid #81c784;
}

.badge-icon {
  font-size: 14px;
}

/* Card Style */
.expiry-card {
  background: white;
  border-radius: 8px;
  padding: 15px;
  border-left: 4px solid #4caf50;
}

.expiry-card.status-expired {
  border-left-color: #d32f2f;
  background: #fff5f5;
}

.expiry-card.status-expiring {
  border-left-color: #f57c00;
  background: #fffbf0;
}

.expiry-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
  padding-bottom: 10px;
  border-bottom: 1px solid #eee;
}

.expiry-header h3 {
  margin: 0;
  font-size: 14px;
  font-weight: 600;
}

.status-badge {
  padding: 4px 8px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
}

.status-badge.badge-danger {
  background: #ff5252;
  color: white;
}

.status-badge.badge-warning {
  background: #ffb74d;
  color: white;
}

.status-badge.badge-success {
  background: #66bb6a;
  color: white;
}

.expiry-details {
  margin-bottom: 15px;
}

.detail-row {
  display: flex;
  justify-content: space-between;
  padding: 6px 0;
  font-size: 13px;
  border-bottom: 1px solid #f0f0f0;
}

.detail-row:last-child {
  border-bottom: none;
}

.detail-row label {
  font-weight: 500;
  color: #666;
}

.detail-row span {
  font-weight: 600;
  color: #333;
}

.detail-row span.expired {
  color: #d32f2f;
}

.detail-row span.expiring {
  color: #f57c00;
}

.detail-row span.warning {
  color: #f57c00;
  font-weight: 700;
}

.detail-row span.danger {
  color: #d32f2f;
  font-weight: 700;
}

.expiry-date {
  font-weight: 700;
}

/* Progress */
.expiry-progress {
  margin-top: 12px;
}

.progress-label {
  font-size: 12px;
  font-weight: 600;
  margin-bottom: 4px;
  color: #666;
}

.progress-bar {
  width: 100%;
  height: 6px;
  background: #e0e0e0;
  border-radius: 3px;
  overflow: hidden;
  position: relative;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #66bb6a, #ffb74d, #ff5252);
  transition: width 0.3s;
}

.progress-percent {
  font-size: 11px;
  color: #999;
  margin-top: 4px;
  text-align: right;
}

/* Cell Style (for tables) */
.expiry-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
  align-items: flex-start;
}

.cell-date {
  font-size: 13px;
  font-weight: 500;
}

.cell-status {
  font-size: 11px;
  font-weight: 600;
  padding: 2px 6px;
  border-radius: 3px;
}

.cell-status.status-expired {
  background: #ffe6e6;
  color: #d32f2f;
}

.cell-status.status-expiring {
  background: #fff3e0;
  color: #f57c00;
}

.cell-status.status-fresh {
  background: #e8f5e9;
  color: #388e3c;
}
</style>
