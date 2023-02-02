<?php declare(strict_types=1);

require_once("Request.php");

Request::handle($_SERVER['REQUEST_URI'], $_GET, $_POST);
