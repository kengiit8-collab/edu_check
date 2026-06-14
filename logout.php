<?php

require_once __DIR__ . '/lib.php';
session_destroy();
redirect('index.php');
