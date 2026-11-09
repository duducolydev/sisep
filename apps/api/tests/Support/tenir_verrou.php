<?php

declare(strict_types=1);

/*
 * Un autre valideur, sur sa propre connexion MySQL : il pose le verrou, valide
 * une écriture, signale qu'il tient le verrou, puis valide sa transaction un
 * peu plus tard. Le test appelant arrive pendant ce temps et doit attendre.
 *
 * Arguments : hôte port base utilisateur mot_de_passe table_verrouillée id_verrouillé table_validée id_validé id_valideur
 * Utilisé par ValidationConcurrenteTest.
 */

[, $hote, $port, $base, $utilisateur, $motDePasse, $tableVerrou, $idVerrou, $table, $idEcriture, $idValideur] = $argv;

$pdo = new PDO("mysql:host={$hote};port={$port};dbname={$base}", $utilisateur, $motDePasse, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->beginTransaction();
$pdo->query(sprintf('SELECT id FROM %s WHERE id = %d FOR UPDATE', $tableVerrou, (int) $idVerrou))->fetchAll();
$pdo->exec(sprintf("UPDATE %s SET statut = 'valide', valide_le = NOW(), valide_par_id = %d WHERE id = %d", $table, (int) $idValideur, (int) $idEcriture));

echo "verrou\n";
flush();

sleep(2);
$pdo->commit();
