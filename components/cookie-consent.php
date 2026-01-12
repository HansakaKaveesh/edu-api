<?php
/**
 * Cookie Consent Component
 * Include this file at the end of your page, before the closing </body> tag
 * Usage: <?php include 'components/cookie-consent.php'; ?>
 */
?>

<!-- Cookie Consent Banner -->
<div id="cookieBanner" class="fixed inset-x-0 bottom-0 sm:bottom-6 px-4 sm:px-6 hidden z-[60]">
  <div class="mx-auto max-w-4xl rounded-2xl border border-gray-200 bg-white/95 backdrop-blur shadow-lg p-4 sm:p-5">
    <div class="flex items-start gap-4">
      <div class="shrink-0 mt-1 text-blue-600" aria-hidden="true">
        <ion-icon name="cookie-outline" class="text-xl"></ion-icon>
      </div>
      <div class="text-sm text-gray-700">
        <p class="font-medium text-gray-900">We use cookies</p>
        <p class="mt-1">
          We use essential cookies to make our site work. With your consent, we may also use analytics cookies to
          understand how you use SynapZ and improve your experience. See our
          <a href="privacy.php" class="text-blue-600 underline hover:text-blue-700">Privacy Policy</a>.
        </p>
        <div class="mt-3 flex flex-wrap gap-2">
          <button id="cc-accept" class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-white text-sm font-medium hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-300 transition-colors">
            <ion-icon name="checkmark-circle-outline"></ion-icon> Accept all
          </button>
          <button id="cc-reject" class="inline-flex items-center gap-2 rounded-lg bg-gray-100 px-4 py-2 text-gray-800 text-sm font-medium hover:bg-gray-200 focus:outline-none focus:ring-4 focus:ring-gray-200 transition-colors">
            <ion-icon name="close-circle-outline"></ion-icon> Reject non‑essential
          </button>
          <button id="cc-settings" class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-50 focus:outline-none transition-colors">
            <ion-icon name="options-outline"></ion-icon> Manage settings
          </button>
        </div>
      </div>
      <button id="cc-close" aria-label="Close cookie banner" class="ml-auto text-gray-400 hover:text-gray-600 transition-colors p-1">
        <ion-icon name="close-outline" class="text-xl"></ion-icon>
      </button>
    </div>
  </div>
</div>

<!-- Cookie Settings Modal -->
<div id="cookieModal" class="fixed inset-0 hidden items-end sm:items-center justify-center p-4 z-[70]" role="dialog" aria-modal="true" aria-labelledby="cookieModalTitle">
  <!-- Backdrop -->
  <div class="absolute inset-0 bg-black/40 transition-opacity" id="cookieModalBackdrop" aria-hidden="true"></div>
  
  <!-- Modal Content -->
  <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-xl border border-gray-200 p-6 transform transition-all">
    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
      <h3 id="cookieModalTitle" class="text-lg font-semibold text-gray-900">Cookie preferences</h3>
      <button id="cc-modal-close" aria-label="Close modal" class="text-gray-400 hover:text-gray-600 transition-colors p-1">
        <ion-icon name="close-outline" class="text-xl"></ion-icon>
      </button>
    </div>
    
    <p class="text-sm text-gray-600 mb-6">
      Control which cookies we use. Necessary cookies are always on—they're required for the site to function properly.
    </p>

    <!-- Cookie Options -->
    <div class="space-y-4">
      <!-- Necessary Cookies (Always On) -->
      <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg border border-gray-100">
        <div class="mt-0.5">
          <input type="checkbox" checked disabled 
                 class="h-4 w-4 text-blue-600 border-gray-300 rounded cursor-not-allowed opacity-60"
                 aria-describedby="necessary-desc">
        </div>
        <div class="flex-1">
          <div class="flex items-center gap-2">
            <span class="font-medium text-gray-900">Necessary</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
              Always on
            </span>
          </div>
          <p id="necessary-desc" class="text-sm text-gray-600 mt-1">
            Required for core features like login, security, and basic functionality.
          </p>
        </div>
      </div>

      <!-- Analytics Cookies -->
      <label class="flex items-start gap-3 p-3 bg-white rounded-lg border border-gray-200 hover:border-gray-300 transition-colors cursor-pointer">
        <div class="mt-0.5">
          <input id="cc-analytics" type="checkbox" 
                 class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 focus:ring-2 cursor-pointer"
                 aria-describedby="analytics-desc">
        </div>
        <div class="flex-1">
          <span class="font-medium text-gray-900">Analytics</span>
          <p id="analytics-desc" class="text-sm text-gray-600 mt-1">
            Helps us understand how you use SynapZ so we can improve your experience.
          </p>
        </div>
      </label>

      <!-- Marketing Cookies -->
      <label class="flex items-start gap-3 p-3 bg-white rounded-lg border border-gray-200 hover:border-gray-300 transition-colors cursor-pointer">
        <div class="mt-0.5">
          <input id="cc-marketing" type="checkbox" 
                 class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 focus:ring-2 cursor-pointer"
                 aria-describedby="marketing-desc">
        </div>
        <div class="flex-1">
          <div class="flex items-center gap-2">
            <span class="font-medium text-gray-900">Marketing</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">
              Optional
            </span>
          </div>
          <p id="marketing-desc" class="text-sm text-gray-600 mt-1">
            Used to personalize content and show relevant information across services.
          </p>
        </div>
      </label>
    </div>

    <!-- Actions -->
    <div class="mt-6 flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
      <button id="cc-cancel" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium text-gray-700 hover:text-gray-900 hover:bg-gray-100 transition-colors">
        <ion-icon name="close-outline"></ion-icon> Cancel
      </button>
      <button id="cc-save" class="inline-flex items-center gap-2 px-5 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-300 transition-colors">
        <ion-icon name="checkmark-outline"></ion-icon> Save preferences
      </button>
    </div>
  </div>
</div>

<!-- Cookie Consent Styles -->
<style>
  /* Cookie banner entrance animation */
  #cookieBanner {
    animation: slideUp 0.4s ease-out;
  }
  
  @keyframes slideUp {
    from {
      opacity: 0;
      transform: translateY(20px);
    }
    to {
      opacity: 1;
      transform: translateY(0);
    }
  }
  
  /* Modal backdrop fade */
  #cookieModal[class*="flex"] #cookieModalBackdrop {
    animation: fadeIn 0.2s ease-out;
  }
  
  @keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
  }
  
  /* Modal content slide up */
  #cookieModal[class*="flex"] > div:last-child {
    animation: modalSlideUp 0.3s ease-out;
  }
  
  @keyframes modalSlideUp {
    from {
      opacity: 0;
      transform: translateY(10px) scale(0.98);
    }
    to {
      opacity: 1;
      transform: translateY(0) scale(1);
    }
  }
  
  /* Reduced motion */
  @media (prefers-reduced-motion: reduce) {
    #cookieBanner,
    #cookieModal[class*="flex"] #cookieModalBackdrop,
    #cookieModal[class*="flex"] > div:last-child {
      animation: none;
    }
  }
</style>

<!-- Cookie Consent JavaScript -->
<script>
(function() {
  'use strict';
  
  // Configuration
  const CONFIG = {
    storageKey: 'synapz_cookie_consent_v1',
    cookieName: 'cookie_consent',
    expiryDays: 180, // 6 months
    expiryMs: 180 * 24 * 60 * 60 * 1000
  };
  
  // DOM Elements
  const elements = {
    banner: document.getElementById('cookieBanner'),
    modal: document.getElementById('cookieModal'),
    modalBackdrop: document.getElementById('cookieModalBackdrop'),
    btnAccept: document.getElementById('cc-accept'),
    btnReject: document.getElementById('cc-reject'),
    btnClose: document.getElementById('cc-close'),
    btnSettings: document.getElementById('cc-settings'),
    btnSave: document.getElementById('cc-save'),
    btnCancel: document.getElementById('cc-cancel'),
    btnModalClose: document.getElementById('cc-modal-close'),
    chkAnalytics: document.getElementById('cc-analytics'),
    chkMarketing: document.getElementById('cc-marketing')
  };
  
  // Check if all required elements exist
  if (!elements.banner || !elements.modal) {
    console.warn('Cookie consent: Required elements not found');
    return;
  }
  
  /**
   * Read consent from localStorage
   * @returns {Object|null} Consent object or null if not found/expired
   */
  function readConsent() {
    try {
      const raw = localStorage.getItem(CONFIG.storageKey);
      if (!raw) return null;
      
      const data = JSON.parse(raw);
      if (!data || !data.updatedAt) return null;
      
      // Check if consent has expired
      if (Date.now() - data.updatedAt > CONFIG.expiryMs) {
        localStorage.removeItem(CONFIG.storageKey);
        return null;
      }
      
      return data;
    } catch (e) {
      console.warn('Cookie consent: Error reading consent', e);
      return null;
    }
  }
  
  /**
   * Save consent to localStorage and set cookie
   * @param {Object} consent - Consent preferences
   */
  function saveConsent(consent) {
    const payload = {
      necessary: true, // Always true
      analytics: Boolean(consent.analytics),
      marketing: Boolean(consent.marketing),
      updatedAt: Date.now(),
      version: '1.0'
    };
    
    try {
      localStorage.setItem(CONFIG.storageKey, JSON.stringify(payload));
      
      // Set a simple cookie for server-side detection
      const expiryDate = new Date(Date.now() + CONFIG.expiryMs).toUTCString();
      document.cookie = `${CONFIG.cookieName}=1; expires=${expiryDate}; path=/; SameSite=Lax`;
      
      applyConsent(payload);
      
      // Dispatch custom event for other scripts to listen
      window.dispatchEvent(new CustomEvent('cookieConsentUpdated', { detail: payload }));
      
    } catch (e) {
      console.error('Cookie consent: Error saving consent', e);
    }
  }
  
  /**
   * Apply consent preferences (load/block scripts)
   * @param {Object} consent - Consent preferences
   */
  function applyConsent(consent) {
    // Analytics
    if (consent.analytics) {
      // Example: Load Google Analytics
      // loadGoogleAnalytics();
      document.body.dataset.analyticsConsent = 'true';
    } else {
      document.body.dataset.analyticsConsent = 'false';
    }
    
    // Marketing
    if (consent.marketing) {
      // Example: Load marketing scripts
      // loadMarketingScripts();
      document.body.dataset.marketingConsent = 'true';
    } else {
      document.body.dataset.marketingConsent = 'false';
    }
    
    // Log for debugging (remove in production)
    if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
      console.log('Cookie consent applied:', consent);
    }
  }
  
  /**
   * Show the cookie banner
   */
  function showBanner() {
    if (elements.banner) {
      elements.banner.classList.remove('hidden');
      elements.banner.setAttribute('aria-hidden', 'false');
    }
  }
  
  /**
   * Hide the cookie banner
   */
  function hideBanner() {
    if (elements.banner) {
      elements.banner.classList.add('hidden');
      elements.banner.setAttribute('aria-hidden', 'true');
    }
  }
  
  /**
   * Open the settings modal
   */
  function openModal() {
    if (!elements.modal) return;
    
    // Load current preferences
    const current = readConsent() || { analytics: false, marketing: false };
    if (elements.chkAnalytics) elements.chkAnalytics.checked = Boolean(current.analytics);
    if (elements.chkMarketing) elements.chkMarketing.checked = Boolean(current.marketing);
    
    // Show modal
    elements.modal.classList.remove('hidden');
    elements.modal.classList.add('flex');
    elements.modal.setAttribute('aria-hidden', 'false');
    
    // Trap focus
    document.body.style.overflow = 'hidden';
    
    // Focus first interactive element
    setTimeout(() => {
      if (elements.chkAnalytics) elements.chkAnalytics.focus();
    }, 100);
  }
  
  /**
   * Close the settings modal
   */
  function closeModal() {
    if (!elements.modal) return;
    
    elements.modal.classList.add('hidden');
    elements.modal.classList.remove('flex');
    elements.modal.setAttribute('aria-hidden', 'true');
    
    // Restore scroll
    document.body.style.overflow = '';
    
    // Return focus to settings button
    if (elements.btnSettings) elements.btnSettings.focus();
  }
  
  /**
   * Handle accept all
   */
  function handleAcceptAll() {
    saveConsent({ analytics: true, marketing: true });
    hideBanner();
    closeModal();
  }
  
  /**
   * Handle reject non-essential
   */
  function handleRejectAll() {
    saveConsent({ analytics: false, marketing: false });
    hideBanner();
    closeModal();
  }
  
  /**
   * Handle save preferences
   */
  function handleSavePreferences() {
    saveConsent({
      analytics: elements.chkAnalytics?.checked || false,
      marketing: elements.chkMarketing?.checked || false
    });
    closeModal();
    hideBanner();
  }
  
  // Event Listeners
  if (elements.btnAccept) {
    elements.btnAccept.addEventListener('click', handleAcceptAll);
  }
  
  if (elements.btnReject) {
    elements.btnReject.addEventListener('click', handleRejectAll);
  }
  
  if (elements.btnClose) {
    elements.btnClose.addEventListener('click', hideBanner);
  }
  
  if (elements.btnSettings) {
    elements.btnSettings.addEventListener('click', openModal);
  }
  
  if (elements.btnSave) {
    elements.btnSave.addEventListener('click', handleSavePreferences);
  }
  
  if (elements.btnCancel) {
    elements.btnCancel.addEventListener('click', closeModal);
  }
  
  if (elements.btnModalClose) {
    elements.btnModalClose.addEventListener('click', closeModal);
  }
  
  // Close modal on backdrop click
  if (elements.modalBackdrop) {
    elements.modalBackdrop.addEventListener('click', closeModal);
  }
  
  // Close modal on Escape key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && elements.modal && !elements.modal.classList.contains('hidden')) {
      closeModal();
    }
  });
  
  // Initialize
  const existingConsent = readConsent();
  if (existingConsent) {
    applyConsent(existingConsent);
  } else {
    // Show banner after a short delay for better UX
    setTimeout(showBanner, 1000);
  }
  
  // Expose API for programmatic access
  window.CookieConsent = {
    show: showBanner,
    hide: hideBanner,
    openSettings: openModal,
    closeSettings: closeModal,
    acceptAll: handleAcceptAll,
    rejectAll: handleRejectAll,
    getConsent: readConsent,
    setConsent: saveConsent
  };
  
})();
</script>