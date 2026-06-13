<?php
$sports = Athlete::getAllSports();
if ($sports) {
    ?>
    <div class="container">
        <div class="row">
            <?= getTitle('Top Sport') ?>
            <div class="col-12">
                <div class="row">
                    <?php
                    $chunks = array_chunk($sports, 5);
                    foreach ($chunks as $chunk) {
                        ?>
                        <div class="col-6 col-md-3">
                            <?php
                            foreach ($chunk as $s) {
                                ?>
                                <div class="mb-2">
                                    <a class="text-decoration-none" style="color: var(--light)"
                                       href="/network/sport/<?= toSanitize($s['sport']) ?>">
                                        <?= ucwords($s['sport']) ?>
                                    </a>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                        <?php
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <?php
}
?>
