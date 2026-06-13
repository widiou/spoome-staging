<?php
if (isset($obja)) {
    ?>
    <div class="table-responsive">
        <table class="table table-dark">
            <thead>
            <tr>
                <th class="text-center text-light bg-spoome" colspan="2">Scheda</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <th>Nome</th>
                <td><?= $obja->name ?></td>
            </tr>
            <tr>
                <th>Cognome</th>
                <td><?= $obja->surname ?></td>
            </tr>
            <?php
            if ($obja->sesso == 'F') {
                ?>
                <tr>
                    <th>Nato a</th>
                    <td><?= $obja->birthplace ?></td>
                </tr>
                <tr>
                    <th>Nato il</th>
                    <td><?= $obja->birthdate ?></td>
                </tr>
                <?php
            } else {
                ?>
                <tr>
                    <th>Nata a</th>
                    <td><?= $obja->birthplace ?></td>
                </tr>
                <tr>
                    <th>Nata il</th>
                    <td><?= $obja->birthdate ?></td>
                </tr>
                <?php
            }
            ?>
            <tr>
                <th>Classe</th>
                <td><?= $obja->birthyear ?></td>
            </tr>
            <tr>
                <th>Attività</th>
                <td><?= $obja->activity ?></td>
            </tr>
            <tr>
                <th>Nazionalità</th>
                <td><?= $obja->nationality ?></td>
            </tr>
            <tr>
                <th>Sport</th>
                <td><a class="link-spoome" href="/sport.php?sport=<?= $obja->sport ?>"> <?= $obja->sport ?> </a>
                </td>
            </tr>
            </tbody>
        </table>
    </div>
    <?php
}
