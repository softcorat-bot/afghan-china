<template>
  <q-layout view="hHh lpR fFf">
    <q-page-container>
      <q-page class="auth-page">
        <div class="auth-split">

          <!-- LEFT PANEL — Branding & Features -->
          <div class="auth-left">
            <div class="auth-left__inner">

              <!-- VIP logo medallion -->
              <div class="vip-wrap">
                <div class="vip-pill"><q-icon name="workspace_premium" size="15px" class="q-mr-xs" />VIP MANAGEMENT SYSTEM</div>
                <!-- Emblem: no plate — the mark floats in its own light -->
                <div class="vip-emblem">
                  <span class="vip-emblem__halo"></span>
                  <span class="vip-emblem__ring vip-emblem__ring--a"></span>
                  <span class="vip-emblem__ring vip-emblem__ring--b"></span>
                  <span class="vip-emblem__crest"></span>
                  <img :src="acLogoDark" alt="Afghan China Shopping Center" class="vip-emblem__logo">
                  <span class="vip-emblem__sheen"></span>
                  <img :src="acLogoDark" alt="" aria-hidden="true" class="vip-emblem__mirror">
                </div>
                <div class="vip-name">Afghan China Shopping Center</div>
                <div class="vip-divider"><span></span><q-icon name="star" size="13px" /><span></span></div>

                <!-- POS barcode with a live scanner beam -->
                <div class="vip-barcode">
                  <div class="vip-barcode__bars"></div>
                  <div class="vip-barcode__laser"></div>
                  <div class="vip-barcode__txt"><span>AC·STORE·0001</span><span class="vip-barcode__fa">مرکز خرید افغان چین</span></div>
                </div>

                <div class="auth-sub vip-sub">
                  The management system of Afghan China Shopping Center — products,
                  sales, purchases, finance &amp; staff, managed end to end.
                </div>
              </div>

              <!-- Discipline badges -->
              <div class="auth-badges">
                <div class="auth-badge">
                  <div class="auth-badge__ring"><q-icon name="storefront" size="21px" /></div>
                  <div><div class="auth-badge__t">Retail & POS</div><div class="auth-badge__s">پرچون فروشی</div></div>
                </div>
                <div class="auth-badge">
                  <div class="auth-badge__ring"><q-icon name="inventory_2" size="21px" /></div>
                  <div><div class="auth-badge__t">Wholesale & Stock</div><div class="auth-badge__s">عمده فروشی و گدام</div></div>
                </div>
                <div class="auth-badge">
                  <div class="auth-badge__ring"><q-icon name="account_balance_wallet" size="21px" /></div>
                  <div><div class="auth-badge__t">Finance & Accounts</div><div class="auth-badge__s">مالی و حسابات</div></div>
                </div>
              </div>

            </div>

            <!-- Decorative circles + skyline art -->
            <div class="auth-circle auth-circle--1"></div>
            <div class="auth-circle auth-circle--2"></div>
            <div class="auth-skyline"></div>

            <!-- Drifting shopping icons -->
            <q-icon name="shopping_cart" class="auth-drift auth-drift--1" />
            <q-icon name="shopping_bag" class="auth-drift auth-drift--2" />
            <q-icon name="sell" class="auth-drift auth-drift--3" />
            <q-icon name="redeem" class="auth-drift auth-drift--4" />
            <q-icon name="local_mall" class="auth-drift auth-drift--5" />
            <q-icon name="qr_code_2" class="auth-drift auth-drift--6" />

            <!-- Rising gold sparks -->
            <span v-for="n in 7" :key="'sp' + n" class="auth-spark" :class="'auth-spark--' + n"></span>
          </div>

          <!-- RIGHT PANEL — Router view (login / company-create) -->
          <div class="auth-right">
            <div class="auth-right__inner">
              <router-view />
            </div>
            <div class="auth-right__footer">
              © {{ new Date().getFullYear() }} Afghan China Shopping Center · All rights reserved
            </div>
          </div>

        </div>
      </q-page>
    </q-page-container>
  </q-layout>
</template>

<script setup>
import acLogoDark from '@/assets/brand/ac-logo-dark.svg'



</script>

<style scoped>
/* ── Page shell ─────────────────────────────────── */
.auth-page {
  height: 100vh;
  overflow: hidden;
  background: #F0F4F8;
}

.auth-split {
  display: grid;
  grid-template-columns: 1fr 420px;
  height: 100vh;
  overflow: hidden;
}
@media (max-width: 900px) {
  .auth-split { grid-template-columns: 1fr; }
  .auth-left { display: none; }
}

/* ── Left panel ─────────────────────────────────── */
.auth-left {
  background: linear-gradient(145deg, #0A1628 0%, #0D2B4E 40%, #123A66 70%, #8A5A1E 100%);
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px;
}

.auth-left__inner {
  position: relative;
  z-index: 2;
  max-width: 520px;
}

/* Logo */
.auth-logo {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 48px;
}
.auth-logo__icon {
  width: 52px; height: 52px;
  background: rgba(255,255,255,.12);
  border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  border: 1px solid rgba(255,255,255,.2);
}
.auth-logo__name {
  font-size: 22px; font-weight: 800; color: #fff; letter-spacing: -0.3px;
}
.auth-logo__tagline {
  font-size: 12px; color: rgba(255,255,255,.5); margin-top: 2px;
}

/* Headline */
.auth-headline {
  font-size: 38px;
  font-weight: 800;
  color: #fff;
  line-height: 1.2;
  letter-spacing: -1px;
  margin-bottom: 18px;
}
.auth-sub {
  font-size: 15px;
  color: rgba(255,255,255,.65);
  line-height: 1.65;
  margin-bottom: 36px;
}

/* Feature pills */
.auth-features {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 40px;
}
.auth-feat {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 14px;
  color: rgba(255,255,255,.85);
}
.auth-feat__icon {
  width: 30px; height: 30px;
  background: rgba(255,255,255,.1);
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}

/* Stats bar */
.auth-stats {
  display: flex;
  gap: 32px;
  margin-bottom: 32px;
  padding: 20px 24px;
  background: rgba(255,255,255,.08);
  border-radius: 16px;
  border: 1px solid rgba(255,255,255,.12);
}
.auth-stat__val {
  font-size: 26px; font-weight: 800; color: #fff; letter-spacing: -0.5px;
}
.auth-stat__lbl {
  font-size: 11px; color: rgba(255,255,255,.5); margin-top: 2px;
}

/* Business type bubbles */
.auth-biz-row {
  display: flex; flex-wrap: wrap; gap: 8px;
}
.auth-biz {
  padding: 5px 14px;
  background: rgba(255,255,255,.1);
  border: 1px solid rgba(255,255,255,.18);
  border-radius: 20px;
  font-size: 12px;
  color: rgba(255,255,255,.75);
}

/* Decorative circles */
.auth-circle {
  position: absolute;
  border-radius: 50%;
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.06);
  pointer-events: none;
}
.auth-circle--1 { width: 400px; height: 400px; top: -120px; right: -120px; }
.auth-circle--2 { width: 280px; height: 280px; bottom: 40px; left: -80px; }
.auth-circle--3 { width: 180px; height: 180px; bottom: 160px; right: 40px; }

/* ── Right panel ────────────────────────────────── */
.auth-right {
  background: #fff;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 40px 32px;
  box-shadow: -4px 0 40px rgba(0,0,0,.08);
}
.auth-right__inner {
  width: 100%;
  max-width: 360px;
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
}
.auth-right__footer {
  font-size: 11px;
  color: #94A3B8;
  text-align: center;
  padding-top: 24px;
}

/* ── Headline gold + badges ── */
.auth-headline__gold {
  background: linear-gradient(90deg, #E9B44C, #C8862D);
  -webkit-background-clip: text; background-clip: text; color: transparent;
}
.auth-badges { display: flex; gap: 12px; margin: 26px 0 4px; flex-wrap: wrap; justify-content: center; }
.auth-badge {
  display: flex; align-items: center; gap: 10px;
  background: rgba(255, 255, 255, 0.07);
  border: 1px solid rgba(233, 180, 76, 0.35);
  border-radius: 14px; padding: 10px 14px;
  backdrop-filter: blur(6px);
}
.auth-badge__ring {
  width: 40px; height: 40px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  color: #E9B44C; border: 2px solid rgba(233, 180, 76, 0.65);
  background: rgba(233, 180, 76, 0.12);
}
.auth-badge__t { font-size: 12.5px; font-weight: 800; color: #fff; letter-spacing: 0.02em; }
.auth-badge__s { font-size: 10.5px; color: rgba(255, 255, 255, 0.65); }

/* ── Skyline, crane & road art (pure SVG, no assets) ── */
.auth-skyline {
  position: absolute; inset-inline: 0; bottom: 0; height: 58%;
  z-index: 1; pointer-events: none;
  background: url("data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%221300%22 height=%22380%22 viewBox=%220 0 1300 380%22 preserveAspectRatio=%22xMidYMax slice%22%3E %3Cg fill=%22none%22 stroke=%22%23ffffff%22 stroke-opacity=%220.18%22 stroke-width=%222%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22%3E %3Cpath d=%22M70 340V215q0-10 10-10h110q10 0 10 10v125q0 10-10 10H80q-10 0-10-10z%22/%3E %3Cpath d=%22M100 205v-18q0-32 35-32t35 32v18M100 187h70%22/%3E %3Cpath d=%22M95 240h80M95 265h80%22/%3E %3C/g%3E %3Cg fill=%22none%22 stroke=%22%23ffffff%22 stroke-opacity=%220.13%22 stroke-width=%222%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22%3E %3Cpath d=%22M185 350V255q0-9 9-9h92q9 0 9 9v95q0 9-9 9h-92q-9 0-9-9z%22/%3E %3Cpath d=%22M210 246v-14q0-26 30-26t30 26v14%22/%3E %3C/g%3E %3Cg fill=%22none%22 stroke=%22%23E9B44C%22 stroke-opacity=%220.4%22 stroke-width=%222.2%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22%3E %3Cpath d=%22M300 356v-64q0-8 8-8h54q8 0 8 8v64q0 8-8 8h-54q-8 0-8-8z%22/%3E %3Cpath d=%22M318 284v-10q0-18 17-18t17 18v10%22/%3E %3C/g%3E %3Cg stroke=%22%23ffffff%22 stroke-opacity=%220.25%22 fill=%22none%22 stroke-width=%222%22%3E %3Cpath d=%22M395 300v-60q0-14 14-14h52q14 0 14 14v60M380 300h110M395 240l-12-26h24M475 240l12-26h-24%22/%3E %3C/g%3E %3Cg stroke=%22%23E9B44C%22 stroke-opacity=%220.55%22 fill=%22none%22 stroke-width=%222.5%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22%3E %3Cpath d=%22M560 130h34l28 124h126l28-90H610%22/%3E %3Ccircle cx=%22648%22 cy=%22286%22 r=%2213%22/%3E %3Ccircle cx=%22726%22 cy=%22286%22 r=%2213%22/%3E %3Cpath d=%22M666 190v40M696 190v40M726 190v40%22/%3E %3C/g%3E %3Cg stroke=%22%23E9B44C%22 stroke-opacity=%220.35%22 stroke-width=%223%22%3E %3Cpath d=%22M840 250v60M850 250v60M862 250v60M870 250v60M882 250v60M894 250v60M902 250v60M914 250v60M926 250v60%22/%3E %3C/g%3E %3Cg fill=%22none%22 stroke=%22%23ffffff%22 stroke-opacity=%220.30%22 stroke-width=%222%22%3E %3Cpath d=%22M285 120l52 20-14 38-52-20q-10-4-6-14l6-16q4-12 14-8zM268 138a5 5 0 1 0 .1 0%22/%3E %3C/g%3E %3Cg fill=%22none%22 stroke=%22%23ffffff%22 stroke-opacity=%220.16%22 stroke-width=%222%22 stroke-linecap=%22round%22%3E %3Cpath d=%22M1000 160h240M1006 160v200M1234 160v200%22/%3E %3Cpath d=%22M1000 160q15 34 30 0q15 34 30 0q15 34 30 0q15 34 30 0q15 34 30 0q15 34 30 0q15 34 30 0q15 34 30 0%22/%3E %3Cpath d=%22M1030 250h180v80h-180zM1030 290h180M1075 250v80M1150 250v80%22/%3E %3C/g%3E %3Cg fill=%22none%22 stroke=%22%23E9B44C%22 stroke-opacity=%220.45%22 stroke-width=%222%22 stroke-linecap=%22round%22%3E %3Cpath d=%22M1030 160q15 30 30 0M1090 160q15 30 30 0M1150 160q15 30 30 0M1210 160q12 26 24 0%22/%3E %3Ccircle cx=%221120%22 cy=%22215%22 r=%2214%22/%3E %3Cpath d=%22M1114 215h12M1120 209v12%22/%3E %3C/g%3E %3Cg%3E %3Cpath d=%22M0 380 Q 340 316 680 336 T 1300 330 L 1300 380 Z%22 fill=%22%23ffffff%22 fill-opacity=%220.07%22/%3E %3Cpath d=%22M0 362 Q 340 300 680 320 T 1300 314%22 stroke=%22%23E9B44C%22 stroke-opacity=%220.6%22 stroke-width=%223.5%22 stroke-dasharray=%2220 16%22 fill=%22none%22/%3E %3C/g%3E %3Cg stroke=%22%23ffffff%22 stroke-opacity=%220.35%22 stroke-width=%222%22 fill=%22none%22%3E %3Cpath d=%22M900 70q6-8 12 0q6-8 12 0M950 95q5-7 10 0q5-7 10 0M860 55q5-7 10 0q5-7 10 0%22/%3E %3C/g%3E %3C/svg%3E") no-repeat bottom center / cover;
}

/* ── VIP composition ── */
.vip-wrap { display: flex; flex-direction: column; align-items: center; text-align: center; }
.vip-pill {
  display: inline-flex; align-items: center;
  background: linear-gradient(120deg, #F3D48B, #C8862D);
  color: #123A66; font-size: 11.5px; font-weight: 900; letter-spacing: 0.22em;
  border-radius: 999px; padding: 6px 18px; margin-bottom: 26px;
  box-shadow: 0 6px 22px -8px rgba(233, 180, 76, 0.8);
}
.vip-emblem {
  position: relative; width: 320px; height: 356px;
  display: flex; align-items: flex-start; justify-content: center;
  animation: vipfloat 6s ease-in-out infinite;
}
/* warm light pooling behind the mark — this replaces the white card */
.vip-emblem__halo {
  position: absolute; top: 4%; left: 50%; transform: translateX(-50%);
  width: 300px; height: 300px; border-radius: 50%;
  background:
    radial-gradient(circle at 50% 45%, rgba(255, 209, 122, 0.30) 0%, rgba(240, 165, 0, 0.13) 42%, transparent 70%);
  filter: blur(2px);
  animation: vippulse 5.5s ease-in-out infinite;
}
/* two counter-rotating dashed rings = a seal, not a sticker */
.vip-emblem__ring {
  position: absolute; border-radius: 50%; pointer-events: none;
}
.vip-emblem__ring--a {
  top: 2%; left: 50%; width: 292px; height: 292px; margin-inline-start: -146px;
  border: 1.5px dashed rgba(243, 212, 139, 0.55);
  animation: vipspin 26s linear infinite;
}
.vip-emblem__ring--b {
  top: 8%; left: 50%; width: 252px; height: 252px; margin-inline-start: -126px;
  border: 1px solid rgba(243, 212, 139, 0.28);
  box-shadow: inset 0 0 34px rgba(243, 212, 139, 0.16);
  animation: vipspin 34s linear infinite reverse;
}
/* faint gold crest arc under the wordmark */
.vip-emblem__crest {
  position: absolute; bottom: 34px; left: 50%; transform: translateX(-50%);
  width: 210px; height: 210px; border-radius: 50%;
  border-bottom: 2px solid rgba(243, 212, 139, 0.35);
  clip-path: inset(62% 0 0 0);
}
.vip-emblem__logo {
  position: relative; z-index: 2; width: 256px; display: block;
  filter: drop-shadow(0 14px 30px rgba(0, 0, 0, 0.55));
}
/* glass sweep across the mark */
.vip-emblem__sheen {
  position: absolute; z-index: 3; top: 0; height: 84%; width: 62px;
  background: linear-gradient(100deg, transparent, rgba(255, 255, 255, 0.16), transparent);
  transform: skewX(-18deg); inset-inline-start: -90px; pointer-events: none;
  animation: vipshine 5s ease-in-out infinite;
}
/* mirrored fade — the mark stands on light */
.vip-emblem__mirror {
  position: absolute; z-index: 1; bottom: -34px; left: 50%; transform: translateX(-50%) scaleY(-1);
  width: 256px; opacity: 0.16;
  -webkit-mask-image: linear-gradient(to top, rgba(0, 0, 0, 0.85), transparent 46%);
  mask-image: linear-gradient(to top, rgba(0, 0, 0, 0.85), transparent 46%);
  pointer-events: none;
}
@keyframes vipspin { to { transform: rotate(360deg); } }
@keyframes vippulse {
  0%, 100% { opacity: 0.85; transform: translateX(-50%) scale(1); }
  50% { opacity: 1; transform: translateX(-50%) scale(1.06); }
}
.vip-name {
  margin-top: 26px; font-size: 26px; font-weight: 800; color: #fff; letter-spacing: -0.4px;
}
.vip-divider {
  display: flex; align-items: center; gap: 10px; color: #E9B44C; margin: 12px 0 14px;
}
.vip-divider span { display: block; width: 70px; height: 1.5px; background: linear-gradient(90deg, transparent, rgba(233, 180, 76, 0.8), transparent); }
.vip-sub { max-width: 430px; margin-bottom: 10px; }

/* ── POS barcode + scanner beam ── */
.vip-barcode {
  position: relative; width: 250px; margin: 4px auto 16px;
  background: #ffffff; border-radius: 12px; padding: 10px 14px 8px;
  border: 1.5px solid rgba(233, 180, 76, 0.65);
  box-shadow: 0 10px 30px -14px rgba(0, 0, 0, 0.55);
  overflow: hidden;
}
.vip-barcode__bars {
  height: 34px;
  background: repeating-linear-gradient(90deg,
    #123A66 0 2px, transparent 2px 5px,
    #123A66 5px 9px, transparent 9px 11px,
    #123A66 11px 12px, transparent 12px 16px,
    #123A66 16px 19px, transparent 19px 21px,
    #123A66 21px 22px, transparent 22px 26px);
}
.vip-barcode__laser {
  position: absolute; top: 6px; bottom: 22px; width: 3px;
  background: #E53935; border-radius: 3px;
  box-shadow: 0 0 10px 2px rgba(229, 57, 53, 0.75);
  inset-inline-start: 10px;
  animation: vipscan 2.6s ease-in-out infinite;
}
@keyframes vipscan {
  0%, 8% { inset-inline-start: 10px; opacity: 0; }
  12% { opacity: 1; }
  46% { inset-inline-start: calc(100% - 13px); }
  50% { opacity: 1; }
  54% { opacity: 0.25; }
  58% { opacity: 1; }
  88% { inset-inline-start: 10px; }
  92%, 100% { opacity: 0; }
}
.vip-barcode__txt {
  display: flex; justify-content: space-between; align-items: center;
  margin-top: 5px; font-size: 10px; font-weight: 800; letter-spacing: 2px; color: #123A66;
}
.vip-barcode__fa { font-size: 10.5px; letter-spacing: 0; color: #C8862D; }

/* ── Drifting shopping icons ── */
.auth-drift {
  position: absolute; z-index: 1; pointer-events: none;
  color: rgba(233, 180, 76, 0.28);
  animation: authdrift 9s ease-in-out infinite;
}
.auth-drift--1 { top: 12%; left: 8%; font-size: 40px; animation-delay: 0s; }
.auth-drift--2 { top: 24%; right: 10%; font-size: 34px; animation-delay: 1.4s; color: rgba(255, 255, 255, 0.16); }
.auth-drift--3 { top: 55%; left: 5%; font-size: 30px; animation-delay: 2.6s; }
.auth-drift--4 { top: 70%; right: 7%; font-size: 36px; animation-delay: 3.4s; color: rgba(255, 255, 255, 0.14); }
.auth-drift--5 { top: 40%; right: 4%; font-size: 28px; animation-delay: 4.6s; }
.auth-drift--6 { top: 8%; right: 30%; font-size: 30px; animation-delay: 5.4s; color: rgba(255, 255, 255, 0.14); }
@keyframes authdrift {
  0%, 100% { transform: translateY(0) rotate(-4deg); }
  50% { transform: translateY(-16px) rotate(5deg); }
}

/* ── Rising gold sparks ── */
.auth-spark {
  position: absolute; bottom: -8px; width: 5px; height: 5px; border-radius: 50%;
  background: #E9B44C; opacity: 0; z-index: 1; pointer-events: none;
  animation: authspark 7s linear infinite;
}
.auth-spark--1 { left: 12%; animation-delay: 0s; }
.auth-spark--2 { left: 28%; animation-delay: 1.2s; width: 4px; height: 4px; }
.auth-spark--3 { left: 44%; animation-delay: 2.3s; }
.auth-spark--4 { left: 58%; animation-delay: 3.1s; width: 3px; height: 3px; }
.auth-spark--5 { left: 72%; animation-delay: 4.2s; }
.auth-spark--6 { left: 86%; animation-delay: 5.3s; width: 4px; height: 4px; }
.auth-spark--7 { left: 20%; animation-delay: 6.1s; width: 3px; height: 3px; }
@keyframes authspark {
  0% { transform: translateY(0) scale(1); opacity: 0; }
  8% { opacity: 0.9; }
  60% { opacity: 0.5; }
  100% { transform: translateY(-78vh) scale(0.4); opacity: 0; }
}
@media (prefers-reduced-motion: reduce) {
  .auth-drift, .auth-spark, .vip-barcode__laser,
  .vip-emblem, .vip-emblem__halo, .vip-emblem__ring, .vip-emblem__sheen { animation: none; }
}
</style>
