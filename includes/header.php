<?php
$pageTitle = $pageTitle ?? 'HR Management';
$activePage = $activePage ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#10111a">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | PeopleSuite</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="app-shell">