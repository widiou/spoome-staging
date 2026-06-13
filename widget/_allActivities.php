<?php
$sports = Athlete::getAllActivities();
if ($sports) {
    ?>
    <div class="row mb-5">
        <?= getTitle('TOP PROFESSIONI') ?>
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
                                   href="/professione.php?p=<?= $s['activity'] ?>">
                                    <?= ucwords($s['activity']) ?>
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
    <?php
}
?>
