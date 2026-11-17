<?php
// All dates and times on the site are Philippine time — PHP's date()/DateTime
// and the database's NOW() both use this zone (set below).
date_default_timezone_set('Asia/Manila');

// Lagay nyo to sa mga page nyo na need iconnect sa database
// Palitan nyo nalang credentials kung need
//
// --- Supabase (Postgres) connection, via Session pooler (IPv4-compatible) ---
$host = 'db.petggljrythcdbgrmkkt.supabase.co';
$port = '5432';
$dbname = 'postgres';
$username = 'postgres';
$password = 'celestiagems123';

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require",
        $username,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Session pooler (port 5432) supports real server-side prepared statements,
    // so we keep emulation off. If you ever switch to the Transaction pooler
    // (port 6543), this MUST be set to true instead, or prepared statements
    // will break/behave inconsistently across requests.
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->exec("SET TIME ZONE 'Asia/Manila'");
} catch (PDOException $e) {
    // Log the details for the team; never show connection details to visitors.
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(503);
    die("Celestia Gems is temporarily unavailable. Please try again in a few minutes.");
}
?>