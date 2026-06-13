<?php
$nSports = Athlete::getTopTenSports();
?>
<nav class="navbar navbar-expand-xxl navbar-dark fixed-top" style="background: var(--black)" id="navbarTop">
    <div class="container">
        <a class="navbar-brand" href="https://www.spoome.it/network/" style="color: var(--light); font-weight: bold">
            <img class="img-fluid" style="max-width: 120px;"
                 src="<?= SUB_ROOT ?>/assets/logo.webp"
                 alt="<?= T_ALT_LOGO ?>">
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarCollapse"
                aria-controls="navbarCollapse" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarCollapse">
            <ul class="navbar-nav me-auto mb-2 mb-md-0">
                <li class="nav-item">
                    <a class="nav-link nav-link-spoome" href="https://www.spoome.it/network">
                        <span>HOME</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-spoome" role="button" data-bs-toggle="offcanvas"
                       data-bs-target="#offcanvasWithBothOptions" aria-controls="offcanvasWithBothOptions">
                        SPORT
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-spoome" href="https://www.spoome.it/network/organizzazioni">
                        <span>ORGANIZZAZIONI</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-spoome" href="https://www.spoome.it/network/eventi">
                        <span>EVENTI</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-spoome" href="https://spoome.it">
                        <span>TORNA A SPOOME.IT</span>
                    </a>
                </li>
            </ul>

            <div class="d-flex">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link nav-link-spoome" href="<?= SUB_ROOT ?>/ricercaAvanzata.php">
                            <span class="text-uppercase">
                                <i class="bi bi-search pe-2"></i>
                                <?= T_ADV_SEARCH ?>
                            </span>
                        </a>
                    </li>

                    <?php
                    if (checkSessionLive()) {
                        ?>
                        <li class="nav-item">
                            <a class="nav-link nav-link-spoome" href="<?= SUB_ROOT ?>/profilo/dashboard.php">
                                Area personale
                            </a>
                        </li>
                        <?php
                        $tipo = strtolower($_SESSION['user_tipo'] ?? ''); // professionista, atleta, ecc.
                        ?>
                        <li class="nav-item">
                            <a class="nav-link nav-link-spoome" href="<?= SUB_ROOT ?>/profilo/modifica/<?= $tipo ?>.php">
                                <?= ucwords($_SESSION['username'] ?? '') ?>
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="nav-link" href="<?= SUB_ROOT ?>/uac/logout.php">
                                <i class="bi bi-power"></i>
                            </a>
                        </li>
                        <?php
                    }
                    ?>

                </ul>
            </div>
        </div>
    </div>

    <?php if (isset($obja)) : ?>
        <div id="mobile-container" class="container-fluid p-1 m-0 d-none" style="background:var(--yellow)">
            <div class="row text-center mx-auto">
                <div class="col-12">
                    <a role="button" class="link-dark text-decoration-none fw-bold text-uppercase"
                       onclick="window.scrollTo({top: 0});">
                        <i class="bi bi-chevron-double-up me-2"></i><?= $obja->title ?>
                    </a>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener("DOMContentLoaded", function () {
                function toggleContainerVisibility() {
                    const container = document.getElementById("mobile-container");
                    const isScrolled = window.scrollY > 300;
                    const isMobile = window.innerWidth < 576;

                    if (isScrolled && isMobile) {
                        container.classList.remove("d-none");
                    } else {
                        container.classList.add("d-none");
                    }
                }

                document.addEventListener("scroll", toggleContainerVisibility);
                window.addEventListener("resize", toggleContainerVisibility);
                toggleContainerVisibility();
            });
        </script>
    <?php endif; ?>
</nav>

<div class="offcanvas offcanvas-start" data-bs-scroll="true" tabindex="-1" id="offcanvasWithBothOptions"
     aria-labelledby="offcanvasWithBothOptionsLabel">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="offcanvasWithBothOptionsLabel">Gli sport</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <ul class="list-group list-group-flush">
            <?php
            $sports_menu = Athlete::getAllSports('MENU');
            foreach ($sports_menu as $ls) {
                if ($ls) {
                    ?>
                    <li class="list-group-item">
                        <a class="link-light text-decoration-none text-uppercase"
                           href="/network/sport/<?= str_replace(' ', '-', $ls['sport']) ?>">
                            <?= $ls['sport'] ?> <span
                                    class="badge text-bg-light ms-2"><?= $ls['athlete_count'] ?></span>
                        </a>
                    </li>
                    <?php
                }
            }
            ?>
        </ul>
    </div>
</div>
