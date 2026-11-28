<?= view('partials/public_page_head', ['locale' => $locale ?? 'ES', 'title' => $title ?? 'Acceso no disponible']) ?>
<main class="mx-auto max-w-lg px-4 py-10">
    <section class="rounded-card bg-surface-raised p-6 shadow-card" role="alert">
        <h1 class="text-xl font-bold text-ink-strong"><?= esc($title ?? 'Acceso no disponible') ?></h1>
        <p class="mt-2 text-sm leading-relaxed text-ink-muted"><?= esc($message) ?></p>
    </section>
</main>
</body>
</html>
