<?php

foreach (glob(dirname(__DIR__).'/var/build/prod/*.preload.php') ?: [] as $file) {
    require $file;
}
