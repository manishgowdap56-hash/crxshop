<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/maintenance_gate.php';
$user = require_user();

$page_css   = ['store.css'];
$page_js    = ['store.js', 'checkout.js'];
$active_nav = 'store';

require __DIR__ . '/includes/header.php';
?>

<section class="store-page active" id="storePage">
  <div class="sp-wrap">
    <div class="sp-panel">
      <div class="sp-heading"><span class="sp-heading-key">🔑</span><span>BUY A PLAN</span></div>

      <label class="sp-label" for="spSearch">SEARCH PRODUCT</label>
      <div class="sp-search">
        <span class="sp-search-icon" aria-hidden="true">🔍</span>
        <input id="spSearch" type="search" placeholder="Search by product name..." autocomplete="off" enterkeyhint="search">
      </div>

      <div class="sp-label">DEVICE FILTER</div>
      <div class="sp-filters" id="spFilters" role="tablist" aria-label="Device filter"></div>
    </div>

    <div class="sp-list" id="spList" aria-live="polite">
      <div class="sp-state">Loading…</div>
    </div>
  </div>
</section>

<!-- PLAN PICKER (existing duration/price choice, shown after BUY RESELLER KEY) -->
<div class="sp-sheet" id="spSheet" aria-hidden="true">
  <div class="sp-sheet-backdrop" id="spSheetBackdrop"></div>
  <section class="sp-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="spSheetTitle">
    <button type="button" class="checkout-close" id="spSheetClose" aria-label="Close">×</button>
    <div class="checkout-kicker">SELECT DURATION</div>
    <h2 id="spSheetTitle">—</h2>
    <div class="sp-plans" id="spPlans"></div>
  </section>
</div>

<!-- CHECKOUT MODAL -->
<div class="store-checkout" id="storeCheckout" aria-hidden="true">
  <div class="store-checkout-backdrop" id="storeCheckoutBackdrop"></div>
  <section class="store-checkout-panel" role="dialog" aria-modal="true">
    <button type="button" class="checkout-close" id="checkoutClose" aria-label="Close">×</button>
    <div class="checkout-kicker">SECURE CHECKOUT</div>
    <h2 id="checkoutTitle">CONFIRM YOUR PURCHASE</h2>
    <div class="checkout-product">
      <div class="checkout-product-icon">🔑</div>
      <div>
        <strong id="checkoutProductName">—</strong>
        <span id="checkoutPlanName">—</span>
      </div>
    </div>

    <div class="checkout-maintenance" id="checkoutMaintenance" aria-hidden="true" style="display:none">
      <div class="maintenance-logo">
        <img id="maintenanceLogo" data-global-logo src="<?= e(panel_logo_url()) ?>" alt="<?= e(panel_name()) ?>">
        <span class="maintenance-spinner"></span>
      </div>
      <div class="maintenance-title">PRODUCT MAINTENANCE MODE</div>
      <p class="maintenance-text">This product is temporarily unavailable while maintenance is in progress. Purchase is blocked until the product is enabled again.</p>
      <div class="maintenance-badge"><span class="maintenance-dot"></span>PURCHASE BLOCKED</div>
    </div>

    <div class="checkout-grid">
      <div class="checkout-box quantity-box">
        <label for="checkoutQuantity">QUANTITY</label>
        <div class="quantity-control">
          <button type="button" id="qtyMinus">−</button>
          <input id="checkoutQuantity" type="number" min="1" max="99" value="1" inputmode="numeric">
          <button type="button" id="qtyPlus">+</button>
        </div>
        <small>How many keys do you want?</small>
      </div>
      <div class="checkout-box">
        <label>WALLET BALANCE</label>
        <strong id="checkoutBalance">₹0.00</strong>
        <div class="checkout-topup-msg" id="checkoutTopupMsg">INSUFFICIENT WALLET BALANCE — PLEASE TOP UP BEFORE PURCHASE.</div>
        <button type="button" class="wallet-link" id="checkoutBalanceBtn">ADD BALANCE →</button>
      </div>
    </div>

    <div class="checkout-total">
      <span>TOTAL</span>
      <strong id="checkoutTotal">₹0.00</strong>
    </div>
    <div class="checkout-stock" id="checkoutStock"></div>
    <div class="checkout-note">Keys are delivered only from the matching duration/product stock.</div>
    <button type="button" class="checkout-buy" id="checkoutBuy" disabled>⚡ BUY NOW &amp; DELIVER KEY</button>
  </section>
</div>

<!-- KEY DELIVERY MODAL -->
<div class="delivery-modal" id="deliveryModal" aria-hidden="true">
  <div class="delivery-backdrop" id="deliveryBackdrop"></div>
  <section class="delivery-panel" role="dialog" aria-modal="true">
    <div class="delivery-kicker">INSTANT DELIVERY</div>
    <h2 class="delivery-title" id="deliveryTitle">KEYS DELIVERED SUCCESSFULLY</h2>
    <p class="delivery-sub">Your purchased keys are shown below.</p>
    <div class="delivery-summary">
      <span id="deliveryProduct">PRODUCT</span>
      <strong id="deliveryPlan">PLAN</strong>
    </div>
    <button type="button" class="copy-all" id="copyAllKeys">COPY ALL KEYS</button>
    <div class="key-list" id="deliveredKeyList"></div>
    <button type="button" class="delivery-close" id="deliveryClose">DONE</button>
  </section>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>