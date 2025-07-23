<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<footer class="bg-blue-900 text-white  pt-10 pb-6 px-6">
  <div class="max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-4 gap-8 text-sm md:text-base">

    <!-- About SynapZ -->
    <div>
      <h4 class="text-yellow-300 font-semibold mb-2">About SynapZ</h4>
      <p class="text-gray-300">
        SynapZ is an all-in-one learning management platform supporting students, teachers, and administrators to collaborate and grow.
      </p>
    </div>

    <!-- Quick Links -->
    <div>
      <h4 class="text-yellow-300 font-semibold mb-2">Quick Links</h4>
      <ul class="space-y-1">
        <li><a href="/index.php" class="hover:text-yellow-400 transition">Home</a></li>
        <li><a href="#courses" class="hover:text-yellow-400 transition">Courses</a></li>
        <li><a href="#tutors" class="hover:text-yellow-400 transition">Tutors</a></li>
        <li><a href="#contact" class="hover:text-yellow-400 transition">Contact</a></li>
        <?php if (!isset($_SESSION['user_id'])): ?>
          <li><a href="/login.php" class="hover:text-yellow-400 transition">Login</a></li>
        <?php endif; ?>
      </ul>
    </div>

    <!-- Newsletter Form -->
    <div>
      <h4 class="text-yellow-300 font-semibold mb-2">Subscribe to our Newsletter</h4>
      <form method="POST" action="#">
        <input type="email" name="newsletter_email" placeholder="you@example.com"
               class="w-full bg-white text-black px-3 py-2 rounded mb-2 focus:outline-none focus:ring focus:ring-yellow-300" required>
        <button type="submit" class="w-full bg-yellow-400 text-black font-semibold px-3 py-2 rounded hover:bg-yellow-300 transition">
          Subscribe
        </button>
      </form>
    </div>

    <!-- Language + Socials -->
    <div>
      <h4 class="text-yellow-300 font-semibold mb-2">Connect with Us</h4>
      <!-- Social Icons -->
      <div class="flex space-x-4 text-xl mt-1 mb-3">
        <a href="https://facebook.com" target="_blank" rel="noopener" aria-label="Facebook" class="hover:text-yellow-400 transition">
          <!-- Facebook SVG -->
          <svg fill="currentColor" viewBox="0 0 24 24" class="w-6 h-6"><path d="M22.675 0h-21.35C.595 0 0 .592 0 1.326v21.348C0 23.408.595 24 1.325 24h11.495v-9.294H9.692v-3.622h3.128V8.413c0-3.1 1.893-4.788 4.659-4.788 1.325 0 2.463.099 2.797.143v3.24l-1.918.001c-1.504 0-1.797.715-1.797 1.763v2.313h3.587l-.467 3.622h-3.12V24h6.116C23.406 24 24 23.408 24 22.674V1.326C24 .592 23.406 0 22.675 0"></path></svg>
        </a>
        <a href="https://twitter.com" target="_blank" rel="noopener" aria-label="Twitter" class="hover:text-yellow-400 transition">
          <!-- Twitter SVG -->
          <svg fill="currentColor" viewBox="0 0 24 24" class="w-6 h-6"><path d="M24 4.557a9.83 9.83 0 0 1-2.828.775 4.932 4.932 0 0 0 2.165-2.724c-.951.564-2.005.974-3.127 1.195a4.916 4.916 0 0 0-8.38 4.482C7.691 8.095 4.066 6.13 1.64 3.161c-.542.929-.856 2.01-.857 3.17 0 2.188 1.115 4.116 2.823 5.247a4.904 4.904 0 0 1-2.229-.616c-.054 2.281 1.581 4.415 3.949 4.89a4.936 4.936 0 0 1-2.224.084c.627 1.956 2.444 3.377 4.6 3.417A9.867 9.867 0 0 1 0 21.543a13.94 13.94 0 0 0 7.548 2.209c9.058 0 14.009-7.496 14.009-13.986 0-.21 0-.423-.016-.634A9.936 9.936 0 0 0 24 4.557z"></path></svg>
        </a>
        <a href="https://linkedin.com" target="_blank" rel="noopener" aria-label="LinkedIn" class="hover:text-yellow-400 transition">
          <!-- LinkedIn SVG -->
          <svg fill="currentColor" viewBox="0 0 24 24" class="w-6 h-6"><path d="M19 0h-14c-2.76 0-5 2.24-5 5v14c0 2.76 2.24 5 5 5h14c2.76 0 5-2.24 5-5v-14c0-2.76-2.24-5-5-5zm-11.75 20h-3v-10h3v10zm-1.5-11.25c-.966 0-1.75-.784-1.75-1.75s.784-1.75 1.75-1.75 1.75.784 1.75 1.75-.784 1.75-1.75 1.75zm15.25 11.25h-3v-5.5c0-1.381-.028-3.156-1.922-3.156-1.922 0-2.218 1.5-2.218 3.051v5.605h-3v-10h2.881v1.367h.041c.401-.761 1.381-1.563 2.844-1.563 3.041 0 3.602 2.002 3.602 4.604v5.592z"></path></svg>
        </a>
        <a href="https://youtube.com" target="_blank" rel="noopener" aria-label="YouTube" class="hover:text-yellow-400 transition">
          <!-- YouTube SVG -->
          <svg fill="currentColor" viewBox="0 0 24 24" class="w-6 h-6"><path d="M23.498 6.186a2.994 2.994 0 0 0-2.107-2.117C19.163 3.5 12 3.5 12 3.5s-7.163 0-9.391.569A2.994 2.994 0 0 0 .502 6.186C0 8.414 0 12 0 12s0 3.586.502 5.814a2.994 2.994 0 0 0 2.107 2.117C4.837 20.5 12 20.5 12 20.5s7.163 0 9.391-.569a2.994 2.994 0 0 0 2.107-2.117C24 15.586 24 12 24 12s0-3.586-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"></path></svg>
        </a>
      </div>

      <!-- Language Switcher -->
      <form method="POST" action="#">
        <label for="language" class="block mb-1">🌍 Language:</label>
        <select name="language" id="language"
                class="w-full bg-white text-black px-2 py-1 rounded focus:outline-none">
          <option value="en">English</option>
          <option value="fr">Français</option>
          <option value="es">Español</option>
          <option value="de">Deutsch</option>
        </select>
      </form>
    </div>

  </div>

  <!-- Footer Bottom -->
  <div class="text-center text-gray-400 mt-10 text-sm">
    &copy; <?= date("Y") ?> Synap<span class="text-yellow-300">Z</span>. All rights reserved.
  </div>
</footer>