<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<footer class="relative bg-blue-900 text-white pt-16 pb-10 px-6">
  <!-- Background Image and Overlay -->
  <div class="absolute inset-0 bg-cover bg-center bg-no-repeat"
       style="background-image: url('https://media.gettyimages.com/id/1469875556/video/4k-abstract-lines-background-loopable.jpg?s=640x640&k=20&c=oRhmLOFm1rQPZQSQrUqnd8eRd8LsoGLmiQS7nMIh-MU=');">
    <div class="absolute inset-0 bg-blue-900 bg-opacity-80"></div>
  </div>

  <!-- Content -->
  <div class="relative z-10 max-w-7xl mx-auto grid grid-cols-1 md:grid-cols-4 gap-10 text-sm md:text-base">

    <!-- About -->
    <div>
      <h4 class="text-yellow-400 text-lg font-semibold mb-3">About SynapZ</h4>
      <p class="text-gray-300 leading-relaxed">
        SynapZ is an all-in-one learning platform empowering students, teachers, and admins to collaborate and grow in a smart digital space.
      </p>
    </div>

    <!-- Quick Links -->
    <div>
      <h4 class="text-yellow-400 text-lg font-semibold mb-3">Quick Links</h4>
      <ul class="space-y-2">
        <li><a href="/index.php" class="hover:text-yellow-300 transition">🏠 Home</a></li>
        <li><a href="#courses" class="hover:text-yellow-300 transition">📚 Courses</a></li>
        <li><a href="#tutors" class="hover:text-yellow-300 transition">👨‍🏫 Tutors</a></li>
        <li><a href="#contact" class="hover:text-yellow-300 transition">📬 Contact</a></li>
        <?php if (!isset($_SESSION['user_id'])): ?>
          <li><a href="/login.php" class="hover:text-yellow-300 transition">🔐 Login</a></li>
        <?php endif; ?>
      </ul>
    </div>

    <!-- Newsletter -->
    <div>
      <h4 class="text-yellow-400 text-lg font-semibold mb-3">Newsletter</h4>
      <p class="text-gray-300 mb-2">Stay updated with our latest courses and news.</p>
      <form method="POST" action="#">
        <input type="email" name="newsletter_email" placeholder="you@example.com"
               class="w-full bg-white text-black px-3 py-2 rounded-md mb-2 focus:outline-none focus:ring-2 focus:ring-yellow-300" required>
        <button type="submit" class="w-full bg-yellow-400 text-black font-semibold px-3 py-2 rounded-md hover:bg-yellow-300 transition">
          ✉️ Subscribe
        </button>
      </form>
    </div>

    <!-- Contact Us -->
    <div>
      <h4 class="text-yellow-400 text-lg font-semibold mb-3">Contact Us</h4>
      <ul class="text-gray-300 space-y-2">
        <li>📍 123 SynapZ Lane, Colombo, Sri Lanka</li>
        <li>📞 +94 71 234 5678</li>
        <li>✉️ support@synapz.lk</li>
      </ul>
      <div class="flex gap-4 text-xl mt-4">
        <a href="https://facebook.com" target="_blank" class="hover:text-yellow-300" aria-label="Facebook">
          <i class="fab fa-facebook-f"></i>
        </a>
        <a href="https://twitter.com" target="_blank" class="hover:text-yellow-300" aria-label="Twitter">
          <i class="fab fa-twitter"></i>
        </a>
        <a href="https://linkedin.com" target="_blank" class="hover:text-yellow-300" aria-label="LinkedIn">
          <i class="fab fa-linkedin-in"></i>
        </a>
        <a href="https://youtube.com" target="_blank" class="hover:text-yellow-300" aria-label="YouTube">
          <i class="fab fa-youtube"></i>
        </a>
      </div>
    </div>
  </div>

  <!-- Footer Bottom -->
  <div class="relative z-10 text-center text-gray-300 text-sm border-t border-blue-800 pt-6 mt-12">
    &copy; <?= date("Y") ?> <span class="text-white font-semibold">Synap<span class="text-yellow-400">Z</span></span>. All rights reserved.
  </div>
</footer>

<!-- Font Awesome Icons -->
<script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
