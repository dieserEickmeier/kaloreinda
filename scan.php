<?php
// scan.php leitet dauerhaft auf log.php (jetzt "Erfassen") um.
// Bestehende Lesezeichen/Links bleiben damit funktionsfähig.
header('Location: /log.php', true, 301);
exit;
