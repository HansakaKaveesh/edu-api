<?php
$courseId = (int)($_GET['id'] ?? 1);

$courses = [
  1=>[
    'title'=>'Intro to Web Development',
    'instructor'=>'Jane Doe',
    'duration'=>'6 weeks',
    'price'=>99,
    'description'=>"Learn HTML, CSS, and basic PHP to build dynamic websites. This beginner‑friendly course guides you step‑by‑step from zero to publishing your first site.",
    'image'=>'https://source.unsplash.com/1200x600/?web-development,code',
  ],
  2=>[
    'title'=>'Advanced PHP Masterclass',
    'instructor'=>'John Smith',
    'duration'=>'8 weeks',
    'price'=>149,
    'description'=>"Dive into OOP, APIs, and modern PHP architecture. Perfect for developers wanting secure and maintainable apps.",
    'image'=>'https://source.unsplash.com/1200x600/?php,backend',
  ],
];
$course=$courses[$courseId]??null;
if(!$course){echo "<h1 class='text-center text-red-500 mt-10 text-2xl font-bold'>Course not found 😔</h1>";exit;}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=htmlspecialchars($course['title'])?> | Course Info</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<style>
body{font-family:'Inter',ui-sans-serif;}
.fade-up{opacity:0;transform:translateY(20px);animation:fadeUp .7s ease forwards;}
@keyframes fadeUp{to{opacity:1;transform:translateY(0);}}
</style>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen flex flex-col">
<?php include 'components/navbar.php'; ?>

<!-- HERO -->
<section class="relative overflow-hidden">
  <img src="<?=htmlspecialchars($course['image']);?>" alt="<?=htmlspecialchars($course['title']);?>"
       class="w-full h-72 md:h-[420px] object-cover brightness-90">
  <div class="absolute inset-0 bg-gradient-to-r from-indigo-900/80 via-blue-800/60 to-transparent"></div>

  <div class="absolute inset-0 flex items-center">
    <div class="max-w-6xl mx-auto px-8 text-white fade-up">
      <h1 class="text-3xl md:text-5xl font-extrabold mb-4"><?=htmlspecialchars($course['title']);?></h1>
      <div class="flex items-center gap-3 text-sm mb-3">
        <i class="ph ph-user text-xl"></i><span><?=htmlspecialchars($course['instructor']);?></span>
        <span class="mx-2 text-white/40">|</span>
        <i class="ph ph-timer text-xl"></i><span><?=htmlspecialchars($course['duration']);?></span>
      </div>
      <div class="flex items-center gap-1 text-yellow-300 text-lg mb-5">
        <i class="ph-fill ph-star"></i><i class="ph-fill ph-star"></i><i class="ph-fill ph-star"></i>
        <i class="ph-fill ph-star-half"></i><i class="ph ph-star"></i>
        <span class="text-sm text-white/80 ml-1">(4.5 / 5)</span>
      </div>
      <a href="#enroll"
         class="inline-flex items-center gap-2 bg-gradient-to-r from-sky-500 to-indigo-600 px-6 py-3 rounded-lg font-semibold text-white shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 transition">
         <i class="ph ph-arrow-right"></i> Enroll Now – $<?=htmlspecialchars($course['price']);?>
      </a>
    </div>
  </div>
</section>

<!-- DETAILS -->
<main class="flex-grow">
  <section class="max-w-6xl mx-auto px-6 py-12 grid lg:grid-cols-3 gap-10">
    <div class="lg:col-span-2">
      <h2 class="text-2xl font-bold mb-4">About this course</h2>
      <p class="text-gray-700 leading-relaxed"><?=htmlspecialchars($course['description']);?></p>
      <div class="mt-6">
        <h3 class="font-semibold text-gray-900 mb-3 flex items-center gap-2"><i class="ph ph-list-checks text-blue-600"></i> What you’ll learn</h3>
        <ul class="space-y-2 text-sm ml-4 list-disc">
          <li>Build modern responsive websites using HTML and CSS.</li>
          <li>Understand core PHP concepts and syntax.</li>
          <li>Deploy and maintain basic projects online.</li>
        </ul>
      </div>
    </div>

    <!-- RIGHT: summary card -->
    <aside class="bg-white rounded-2xl shadow-md p-6 border border-gray-100 h-fit fade-up">
      <h3 class="text-xl font-bold text-gray-900 mb-4 flex items-center gap-2"><i class="ph ph-info"></i> Course Summary</h3>
      <p class="text-gray-600 mb-3"><strong>Instructor:</strong> <?=htmlspecialchars($course['instructor']);?></p>
      <p class="text-gray-600 mb-3"><strong>Duration:</strong> <?=htmlspecialchars($course['duration']);?></p>
      <p class="text-gray-600 mb-3"><strong>Price:</strong> $<?=htmlspecialchars($course['price']);?> USD</p>
      <a id="enroll" href="#"
         class="mt-4 block w-full text-center bg-gradient-to-r from-indigo-600 to-blue-600 text-white py-2.5 rounded-lg font-semibold hover:from-indigo-700 hover:to-blue-700 transition">
         <i class="ph ph-handshake text-lg"></i> Join Now
      </a>
    </aside>
  </section>

  <!-- RELATED -->
  <section class="max-w-6xl mx-auto px-6 mt-10 pb-16">
    <h3 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-2 border-gray-200">Similar Courses</h3>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-8">
      <?php foreach($courses as $id=>$c): if($id===$courseId)continue;?>
      <a href="course.php?id=<?=$id?>"
         class="relative bg-white rounded-xl shadow-md hover:shadow-xl overflow-hidden transform hover:-translate-y-1 transition border border-gray-100">
         <img src="<?=htmlspecialchars($c['image']);?>" alt="<?=htmlspecialchars($c['title']);?>"
              class="h-44 w-full object-cover">
         <div class="absolute inset-0 bg-gradient-to-t from-black/50 via-transparent to-transparent opacity-40"></div>
         <div class="p-4 relative z-10">
           <h4 class="font-semibold text-lg text-gray-900 mb-1"><?=htmlspecialchars($c['title']);?></h4>
           <p class="text-sm text-gray-500 mb-2 flex items-center gap-1">
             <i class="ph ph-user text-gray-400"></i><?=htmlspecialchars($c['instructor']);?>
           </p>
           <div class="flex items-center justify-between text-sm">
             <span class="bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded-full flex items-center gap-1">
               <i class="ph ph-timer"></i><?=htmlspecialchars($c['duration']);?>
             </span>
             <span class="font-bold text-blue-700">$<?=htmlspecialchars($c['price']);?></span>
           </div>
         </div>
      </a>
      <?php endforeach;?>
    </div>
  </section>
</main>

<footer class="bg-gray-100 border-t border-gray-200 text-center text-sm py-6 text-gray-600 mt-auto">
  © <?=date('Y');?> <span class="font-semibold text-indigo-600">MyCourseSite</span> · Designed with ❤️ for learners.
</footer>

<script>
// simple animation trigger on scroll
const els=document.querySelectorAll('.fade-up');
const io=new IntersectionObserver(e=>{e.forEach(x=>{if(x.isIntersecting)x.target.classList.add('opacity-100','translate-y-0');});},{threshold:.2});
els.forEach(el=>io.observe(el));
</script>
</body>
</html>