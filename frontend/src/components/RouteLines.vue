<template>
  <!-- Trazos de ruta decorativos del lienzo; no aportan información. -->
  <svg
    class="pointer-events-none absolute inset-0 size-full text-secondary-900 opacity-[0.06] dark:text-primary-300 dark:opacity-[0.07]"
    viewBox="0 0 1200 900"
    preserveAspectRatio="xMidYMid slice"
    fill="none"
    stroke="currentColor"
    stroke-width="1.4"
    aria-hidden="true"
    focusable="false"
  >
    <path d="M-20 120C180 60 260 220 470 170S760 40 980 110 1240 60 1240 60" />
    <path d="M-20 520c160-40 240 60 420 10s300-160 520-90 300 10 300 10" />
    <path d="M300 -20c40 160-60 260 20 400s220 200 160 520" />
    <path d="M880 -20c-60 140 80 260 20 420s-160 260-60 520" stroke-dasharray="2 10" stroke-linecap="round" />
    <path d="M-20 820c240-60 380 10 560-40s360-100 680-20" />
  </svg>
</template>
