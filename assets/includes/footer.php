    <footer class="footer mt-5">
        <div class="container text-center">
            <p class="mb-2"><i class="bi bi-geo-alt-fill"></i> <?php echo $country; ?>, <?php echo $city; ?>, <?php echo $street; ?> <?php echo $hause_no; ?></p>
            <p>&copy; <?php echo date("Y"); ?> <?php echo $business_name; ?>. Todos los derechos reservados.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        document.addEventListener("scroll", function () {
            const navbar = document.querySelector(".navbar");
            if (window.scrollY > 50) {
                navbar.classList.add("bg-dark");
            } else {
                navbar.classList.remove("bg-dark");
            }
        });

        // Ensure promo carousel is initialized and cycles
        document.addEventListener('DOMContentLoaded', function () {
            var el = document.getElementById('promoCarousel');
            if (el && typeof bootstrap !== 'undefined') {
                try {
                    var instance = bootstrap.Carousel.getInstance(el);
                    if (!instance) {
                        instance = new bootstrap.Carousel(el, { interval: 3000, ride: 'carousel', pause: 'hover' });
                    }
                    instance.cycle();
                } catch (e) {
                    console.warn('Carousel init failed', e);
                }
            }
        });
    </script>
</body>

</html>
