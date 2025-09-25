<?php
// Start session and simple (optional) newsletter flash messages
if (session_status() === PHP_SESSION_NONE) session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['newsletter_email'])) {
  $email = filter_var($_POST['newsletter_email'], FILTER_VALIDATE_EMAIL);
  if ($email) {
    $_SESSION['newsletter_success'] = 'Thanks for subscribing! Please check your inbox to confirm.';
  } else {
    $_SESSION['newsletter_error'] = 'Please enter a valid email address.';
  }
}
?>

<footer class="relative overflow-hidden bg-gradient-to-br from-blue-900 via-indigo-900 to-slate-900 text-white pt-24 pb-12 px-6">
  <!-- Decorative wave top edge -->
  <svg class="pointer-events-none absolute top-0 left-0 w-full h-16 -translate-y-1 text-blue-800/50" viewBox="0 0 1440 120" aria-hidden="true" preserveAspectRatio="none">
    <path fill="currentColor" d="M0,64L48,80C96,96,192,128,288,117.3C384,107,480,53,576,64C672,75,768,149,864,176C960,203,1056,181,1152,165.3C1248,149,1344,139,1392,133.3L1440,128L1440,0L1392,0C1344,0,1248,0,1152,0C1056,0,960,0,864,0C768,0,672,0,576,0C480,0,384,0,288,0C192,0,96,0,48,0L0,0Z"></path>
  </svg>

  <!-- Background Image + gradient overlays -->
  <div class="absolute inset-0">
    <div class="absolute inset-0 bg-cover bg-center bg-no-repeat opacity-20"
         style="background-image: url('https://media.gettyimages.com/id/1469875556/video/4k-abstract-lines-background-loopable.jpg?s=640x640&k=20&c=oRhmLOFm1rQPZQSQrUqnd8eRd8LsoGLmiQS7nMIh-MU=');">
    </div>
    <div class="absolute inset-0 bg-gradient-to-tr from-blue-900/70 via-indigo-900/70 to-slate-900/70"></div>

    <!-- Soft glow orbs -->
    <div class="pointer-events-none absolute -top-10 left-1/2 h-64 w-64 -translate-x-1/2 rounded-full bg-cyan-400/20 blur-3xl"></div>
    <div class="pointer-events-none absolute -bottom-16 right-10 h-72 w-72 rounded-full bg-fuchsia-500/20 blur-3xl"></div>
  </div>

  <!-- Content -->
  <div class="relative z-10 max-w-7xl mx-auto grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8 text-sm md:text-base">

    <!-- About -->
    <div class="rounded-2xl bg-white/5 backdrop-blur-sm border border-white/10 p-6 shadow-xl shadow-blue-900/20 hover:bg-white/10 transition">
      <h4 class="text-yellow-400 text-lg font-semibold mb-3 inline-flex items-center gap-2">
        <ion-icon name="sparkles-outline" class="text-yellow-300"></ion-icon>
        About SynapZ
      </h4>
      <p class="text-gray-200 leading-relaxed">
        SynapZ is an all-in-one learning platform empowering students, teachers, and admins to collaborate and grow in a smart digital space.
      </p>
      <div class="mt-4 flex items-center gap-3 text-gray-300">
        <span class="inline-flex items-center gap-1 rounded-full bg-white/10 border border-white/10 px-2.5 py-1 text-xs">
          <ion-icon name="star-outline" class="text-yellow-300"></ion-icon>
          4.9/5 by learners
        </span>
        <span class="hidden md:inline-flex items-center gap-1 text-xs text-gray-400">
          <ion-icon name="shield-checkmark-outline"></ion-icon>
          Trusted by schools and tutors
        </span>
      </div>
    </div>

    <!-- Quick Links -->
    <div class="rounded-2xl bg-white/5 backdrop-blur-sm border border-white/10 p-6 shadow-xl shadow-blue-900/20 hover:bg-white/10 transition">
      <h4 class="text-yellow-400 text-lg font-semibold mb-3 inline-flex items-center gap-2">
        <ion-icon name="flash-outline" class="text-yellow-300"></ion-icon>
        Quick Links
      </h4>
      <ul class="space-y-2">
        <li>
          <a href="/index.php" class="group flex items-center gap-2 hover:text-yellow-300 transition">
            <ion-icon name="home-outline" class="text-yellow-300"></ion-icon> Home
            <ion-icon name="chevron-forward-outline" class="ml-auto opacity-0 group-hover:opacity-100 group-hover:translate-x-0.5 transition"></ion-icon>
          </a>
        </li>
        <li>
          <a href="#courses" class="group flex items-center gap-2 hover:text-yellow-300 transition">
            <ion-icon name="library-outline" class="text-yellow-300"></ion-icon> Courses
            <ion-icon name="chevron-forward-outline" class="ml-auto opacity-0 group-hover:opacity-100 group-hover:translate-x-0.5 transition"></ion-icon>
          </a>
        </li>
        <li>
          <a href="#tutors" class="group flex items-center gap-2 hover:text-yellow-300 transition">
            <ion-icon name="people-outline" class="text-yellow-300"></ion-icon> Tutors
            <ion-icon name="chevron-forward-outline" class="ml-auto opacity-0 group-hover:opacity-100 group-hover:translate-x-0.5 transition"></ion-icon>
          </a>
        </li>
        <li>
          <a href="#contact" class="group flex items-center gap-2 hover:text-yellow-300 transition">
            <ion-icon name="mail-outline" class="text-yellow-300"></ion-icon> Contact
            <ion-icon name="chevron-forward-outline" class="ml-auto opacity-0 group-hover:opacity-100 group-hover:translate-x-0.5 transition"></ion-icon>
          </a>
        </li>
        <?php if (!isset($_SESSION['user_id'])): ?>
          <li>
            <a href="/login.php" class="group flex items-center gap-2 hover:text-yellow-300 transition">
              <ion-icon name="log-in-outline" class="text-yellow-300"></ion-icon> Login
              <ion-icon name="chevron-forward-outline" class="ml-auto opacity-0 group-hover:opacity-100 group-hover:translate-x-0.5 transition"></ion-icon>
            </a>
          </li>
        <?php endif; ?>
      </ul>
    </div>

    <!-- Newsletter -->
    <div id="newsletter" class="rounded-2xl bg-white/5 backdrop-blur-sm border border-white/10 p-6 shadow-xl shadow-blue-900/20 hover:bg-white/10 transition">
      <h4 class="text-yellow-400 text-lg font-semibold mb-3 inline-flex items-center gap-2">
        <ion-icon name="paper-plane-outline" class="text-yellow-300"></ion-icon>
        Newsletter
      </h4>
      <p class="text-gray-200 mb-3">Stay updated with our latest courses and news.</p>

      <?php if (!empty($_SESSION['newsletter_success'])): ?>
        <div class="mb-3 rounded-md border border-emerald-400/30 bg-emerald-400/10 text-emerald-200 px-3 py-2 inline-flex items-center gap-1">
          <ion-icon name="checkmark-circle-outline"></ion-icon>
          <?= $_SESSION['newsletter_success']; ?>
        </div>
        <?php unset($_SESSION['newsletter_success']); ?>
      <?php elseif (!empty($_SESSION['newsletter_error'])): ?>
        <div class="mb-3 rounded-md border border-red-400/30 bg-red-400/10 text-red-200 px-3 py-2 inline-flex items-center gap-1">
          <ion-icon name="alert-circle-outline"></ion-icon>
          <?= $_SESSION['newsletter_error']; ?>
        </div>
        <?php unset($_SESSION['newsletter_error']); ?>
      <?php endif; ?>

      <form method="POST" action="#newsletter" class="space-y-2">
        <div class="relative">
          <ion-icon name="mail-open-outline" class="absolute left-3 top-1/2 -translate-y-1/2 text-yellow-300"></ion-icon>
          <input
            type="email"
            name="newsletter_email"
            placeholder="you@example.com"
            class="w-full bg-white text-black pl-10 pr-3 py-2 rounded-md focus:outline-none focus:ring-2 focus:ring-yellow-300"
            required
            aria-label="Email address"
          >
        </div>
        <button type="submit" class="w-full bg-yellow-400 text-black font-semibold px-3 py-2 rounded-md hover:bg-yellow-300 active:scale-[0.99] transition inline-flex items-center justify-center gap-2">
          <ion-icon name="send-outline"></ion-icon> Subscribe
        </button>
        <p class="text-xs text-gray-300/80">
          By subscribing, you agree to our
          <a href="/privacy.php" class="underline hover:text-yellow-300">Privacy Policy</a>.
        </p>
      </form>
    </div>

    <!-- Contact Us -->
    <div class="rounded-2xl bg-white/5 backdrop-blur-sm border border-white/10 p-6 shadow-xl shadow-blue-900/20 hover:bg-white/10 transition" id="contact">
      <h4 class="text-yellow-400 text-lg font-semibold mb-3 inline-flex items-center gap-2">
        <ion-icon name="call-outline" class="text-yellow-300"></ion-icon>
        Contact Us
      </h4>
      <ul class="text-gray-200 space-y-2">
        <li class="flex items-start gap-2">
          <ion-icon name="location-outline" class="text-yellow-300 mt-0.5"></ion-icon>
          <span>123 SynapZ Lane, Colombo, Sri Lanka</span>
        </li>
        <li class="flex items-start gap-2">
          <ion-icon name="call-outline" class="text-yellow-300 mt-0.5"></ion-icon>
          <a href="tel:+94712345678" class="hover:text-yellow-300 transition">+94 71 234 5678</a>
        </li>
        <li class="flex items-start gap-2">
          <ion-icon name="mail-outline" class="text-yellow-300 mt-0.5"></ion-icon>
          <a href="mailto:support@synapz.lk" class="hover:text-yellow-300 transition">support@synapz.lk</a>
        </li>
      </ul>

      <div class="flex gap-3 text-xl mt-4">
        <a href="https://facebook.com" target="_blank" rel="noopener" class="group hover:text-yellow-300 transition" aria-label="Facebook">
          <span class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-white/15 bg-white/5 group-hover:bg-white/10 transition">
            <ion-icon name="logo-facebook"></ion-icon>
          </span>
        </a>
        <a href="https://twitter.com" target="_blank" rel="noopener" class="group hover:text-yellow-300 transition" aria-label="Twitter">
          <span class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-white/15 bg-white/5 group-hover:bg-white/10 transition">
            <ion-icon name="logo-twitter"></ion-icon>
          </span>
        </a>
        <a href="https://linkedin.com" target="_blank" rel="noopener" class="group hover:text-yellow-300 transition" aria-label="LinkedIn">
          <span class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-white/15 bg-white/5 group-hover:bg-white/10 transition">
            <ion-icon name="logo-linkedin"></ion-icon>
          </span>
        </a>
        <a href="https://youtube.com" target="_blank" rel="noopener" class="group hover:text-yellow-300 transition" aria-label="YouTube">
          <span class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-white/15 bg-white/5 group-hover:bg-white/10 transition">
            <ion-icon name="logo-youtube"></ion-icon>
          </span>
        </a>
      </div>
    </div>
  </div>

  <!-- Footer Bottom -->
  <div class="relative z-10 max-w-7xl mx-auto mt-12 pt-6 border-t border-blue-800/60 text-gray-300 text-sm">
    <div class="flex flex-col md:flex-row items-center justify-between gap-4">
      <div class="text-center md:text-left">
        &copy; <?= date("Y") ?> <span class="text-white font-semibold">Synap<span class="text-yellow-400">Z</span></span>. All rights reserved.
      </div>
      <ul class="flex items-center gap-5 text-gray-400">
        <li><a href="/privacy.php" class="hover:text-yellow-300 transition inline-flex items-center gap-1"><ion-icon name="lock-closed-outline"></ion-icon> Privacy</a></li>
        <li><a href="/terms.php" class="hover:text-yellow-300 transition inline-flex items-center gap-1"><ion-icon name="document-text-outline"></ion-icon> Terms</a></li>
        <li><a href="/cookies.php" class="hover:text-yellow-300 transition inline-flex items-center gap-1"><ion-icon name="cookie-outline"></ion-icon> Cookies</a></li>
        <li><a href="#top" class="hover:text-yellow-300 transition inline-flex items-center gap-1"><ion-icon name="arrow-up-outline"></ion-icon> Back to top</a></li>
      </ul>
    </div>
  </div>
</footer>

<!-- Ionicons (icons) -->
<script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
<script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>