<script src="<?= SUB_ROOT ?>/node_modules/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= SUB_ROOT ?>/assets/js/profile.js?<?= rand(0, 1000000) ?>"></script>
<script src="<?= SUB_ROOT ?>/assets/js/search.js?<?= rand(0, 1000000) ?>"></script>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const images = document.querySelectorAll('.profile-photo-circle');
        images.forEach(img => {
            img.style.width = `${Math.min(img.width, img.height)}px !important`;
            img.style.height = `${Math.min(img.width, img.height)}px !important`;
            img.style.borderRadius = '50%';
            img.style.objectFit = 'cover'; // Per evitare deformazioni
        });
    });
</script>
</body>
</html>
