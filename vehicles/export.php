<?php
// Démarrer la session d'abord
session_start();

// Chemin racine de l'application
$root_path = dirname(__DIR__);

// Inclure les fichiers de configuration et de fonctions
if (file_exists($root_path . '/config/database.php')) {
    require_once $root_path . '/config/database.php';
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Récupérer le format d'export (par défaut CSV)
$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'csv';

// Récupérer les filtres éventuels
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$marque = isset($_GET['marque']) ? $_GET['marque'] : '';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Construction de la requête avec filtres
    $whereClause = [];
    $params = [];

    if (!empty($search)) {
        $whereClause[] = "(v.immatriculation LIKE :search OR v.marque LIKE :search OR v.modele LIKE :search OR CONCAT(c.nom, ' ', c.prenom) LIKE :search OR c.raison_sociale LIKE :search)";
        $params[':search'] = "%$search%";
    }

    if ($status !== '') {
        $whereClause[] = "v.statut = :status";
        $params[':status'] = $status;
    }

    if ($marque !== '') {
        $whereClause[] = "v.marque = :marque";
        $params[':marque'] = $marque;
    }

    $whereString = !empty($whereClause) ? 'WHERE ' . implode(' AND ', $whereClause) : '';
    
    // Requête pour récupérer les véhicules avec gestion des types de clients
    $query = "SELECT v.id, v.immatriculation, v.marque, v.modele, v.annee, 
              c.nom, c.prenom, c.raison_sociale, c.telephone, c.email,
              tc.type AS type_client,
              v.kilometrage, v.statut, v.couleur, v.carburant, v.puissance,
              v.date_mise_circulation, v.date_derniere_revision, v.date_prochain_ct, v.notes,
              v.date_creation
              FROM vehicules v 
              LEFT JOIN clients c ON v.client_id = c.id 
              LEFT JOIN type_client tc ON c.type_client_id = tc.id
              $whereString
              ORDER BY v.date_creation DESC";
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Enregistrer l'action dans les logs
    $logQuery = "INSERT INTO logs (user_id, action, entite, entite_id, details, date_action, adresse_ip) 
                VALUES (:user_id, 'Export', 'vehicules', NULL, :details, NOW(), :adresse_ip)";
    
    $logStmt = $db->prepare($logQuery);
    $logStmt->bindParam(':user_id', $_SESSION['user_id']);
    
    $logDetails = "Export des véhicules au format " . strtoupper($format) . " (" . count($vehicles) . " véhicules)";
    $logStmt->bindParam(':details', $logDetails);
    
    $ipAddress = $_SERVER['REMOTE_ADDR'];
    $logStmt->bindParam(':adresse_ip', $ipAddress);
    
    $logStmt->execute();
    
    // Générer le fichier selon le format demandé
    if ($format === 'csv') {
        // Définir les en-têtes pour le téléchargement CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=vehicules_' . date('Y-m-d') . '.csv');
        
        // Créer le flux de sortie
        $output = fopen('php://output', 'w');
        
        // Ajouter le BOM UTF-8 pour Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Écrire les en-têtes
        fputcsv($output, [
            'ID', 'Immatriculation', 'Marque', 'Modèle', 'Année', 'Client', 'Type de client',
            'Téléphone', 'Email', 'Kilométrage', 'Statut', 'Couleur', 
            'Carburant', 'Puissance (CV)', 'Date de mise en circulation', 'Dernière révision', 
            'Prochain CT', 'Notes', 'Date de création'
        ], ';');
        
        // Écrire les données
        foreach ($vehicles as $vehicle) {
            // Déterminer le nom du client selon son type
            $clientName = ($vehicle['type_client'] == 'Société' && !empty($vehicle['raison_sociale'])) 
                ? $vehicle['raison_sociale'] 
                : $vehicle['nom'] . ' ' . $vehicle['prenom'];
            
            fputcsv($output, [
                $vehicle['id'],
                $vehicle['immatriculation'],
                $vehicle['marque'],
                $vehicle['modele'],
                $vehicle['annee'],
                $clientName,
                $vehicle['type_client'],
                $vehicle['telephone'],
                $vehicle['email'],
                $vehicle['kilometrage'],
                $vehicle['statut'],
                $vehicle['couleur'],
                $vehicle['carburant'],
                $vehicle['puissance'],
                $vehicle['date_mise_circulation'],
                $vehicle['date_derniere_revision'],
                $vehicle['date_prochain_ct'],
                $vehicle['notes'],
                $vehicle['date_creation']
            ], ';');
        }
        
        fclose($output);
    } elseif ($format === 'excel') {
        // Vérifier si PhpSpreadsheet est disponible
        if (file_exists($root_path . '/vendor/autoload.php')) {
            require_once $root_path . '/vendor/autoload.php';
            
            // Utiliser PhpSpreadsheet pour créer un vrai fichier Excel
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // Définir les en-têtes
            $headers = [
                'ID', 'Immatriculation', 'Marque', 'Modèle', 'Année', 'Client', 'Type de client',
                'Téléphone', 'Email', 'Kilométrage', 'Statut', 'Couleur', 
                'Carburant', 'Puissance (CV)', 'Date de mise en circulation', 'Dernière révision', 
                'Prochain CT', 'Notes', 'Date de création'
            ];
            
            // Ajouter les en-têtes
            foreach ($headers as $columnIndex => $header) {
                $sheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
            }
            
            // Style des en-têtes
            $headerStyle = $sheet->getStyle('A1:S1');
            $headerStyle->getFont()->setBold(true);
            $headerStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFCCCCCC');
            
            // Ajouter les données
            $row = 2;
            foreach ($vehicles as $vehicle) {
                // Déterminer le nom du client selon son type
                $clientName = ($vehicle['type_client'] == 'Société' && !empty($vehicle['raison_sociale'])) 
                    ? $vehicle['raison_sociale'] 
                    : $vehicle['nom'] . ' ' . $vehicle['prenom'];
                
                $data = [
                    $vehicle['id'],
                    $vehicle['immatriculation'],
                    $vehicle['marque'],
                    $vehicle['modele'],
                    $vehicle['annee'],
                    $clientName,
                    $vehicle['type_client'],
                    $vehicle['telephone'],
                    $vehicle['email'],
                    $vehicle['kilometrage'],
                    $vehicle['statut'],
                    $vehicle['couleur'],
                    $vehicle['carburant'],
                    $vehicle['puissance'],
                    $vehicle['date_mise_circulation'],
                    $vehicle['date_derniere_revision'],
                    $vehicle['date_prochain_ct'],
                    $vehicle['notes'],
                    $vehicle['date_creation']
                ];
                
                foreach ($data as $columnIndex => $value) {
                    $sheet->setCellValueByColumnAndRow($columnIndex + 1, $row, $value);
                }
                
                $row++;
            }
            
            // Auto-dimensionner les colonnes
            foreach (range('A', 'S') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
            
            // Créer le writer
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            
            // Définir les en-têtes HTTP
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename=vehicules_' . date('Y-m-d') . '.xlsx');
            header('Cache-Control: max-age=0');
            
            // Sauvegarder le fichier dans le flux de sortie
            $writer->save('php://output');
        } else {
            // PhpSpreadsheet n'est pas disponible, utiliser CSV avec extension .xlsx
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename=vehicules_' . date('Y-m-d') . '.xlsx');
            
            // Créer le flux de sortie
            $output = fopen('php://output', 'w');
            
            // Ajouter le BOM UTF-8 pour Excel
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Écrire les en-têtes
            fputcsv($output, [
                'ID', 'Immatriculation', 'Marque', 'Modèle', 'Année', 'Client', 'Type de client',
                'Téléphone', 'Email', 'Kilométrage', 'Statut', 'Couleur', 
                'Carburant', 'Puissance (CV)', 'Date de mise en circulation', 'Dernière révision', 
                'Prochain CT', 'Notes', 'Date de création'
            ], ';');
            
            // Écrire les données
            foreach ($vehicles as $vehicle) {
                // Déterminer le nom du client selon son type
                $clientName = ($vehicle['type_client'] == 'Société' && !empty($vehicle['raison_sociale'])) 
                    ? $vehicle['raison_sociale'] 
                    : $vehicle['nom'] . ' ' . $vehicle['prenom'];
                
                fputcsv($output, [
                    $vehicle['id'],
                    $vehicle['immatriculation'],
                    $vehicle['marque'],
                    $vehicle['modele'],
                    $vehicle['annee'],
                    $clientName,
                    $vehicle['type_client'],
                    $vehicle['telephone'],
                    $vehicle['email'],
                    $vehicle['kilometrage'],
                    $vehicle['statut'],
                    $vehicle['couleur'],
                    $vehicle['carburant'],
                    $vehicle['puissance'],
                    $vehicle['date_mise_circulation'],
                    $vehicle['date_derniere_revision'],
                    $vehicle['date_prochain_ct'],
                    $vehicle['notes'],
                    $vehicle['date_creation']
                ], ';');
            }
            
            fclose($output);
        }
    } elseif ($format === 'pdf') {
        // Vérifier si TCPDF est disponible
        if (file_exists($root_path . '/vendor/autoload.php')) {
            require_once $root_path . '/vendor/autoload.php';
            
            // Créer un nouveau document PDF
            $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8');
            
            // Définir les informations du document
            $pdf->SetCreator('Garage Manager');
            $pdf->SetAuthor('Garage Manager');
            $pdf->SetTitle('Liste des véhicules');
            $pdf->SetSubject('Export des véhicules');
            
            // Supprimer les en-têtes et pieds de page par défaut
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Ajouter une page
            $pdf->AddPage();
            
            // Définir la police
            $pdf->SetFont('helvetica', '', 10);
            
            // Titre du document
            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->Cell(0, 10, 'Liste des véhicules', 0, 1, 'C');
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Ln(5);
            
            // En-têtes du tableau
            $pdf->SetFillColor(230, 230, 230);
            $pdf->SetFont('helvetica', 'B', 8);
            
            $headers = [
                'ID', 'Immatriculation', 'Marque', 'Modèle', 'Année', 'Client', 'Type',
                'Téléphone', 'Kilométrage', 'Statut', 'Couleur', 'Carburant'
            ];
            
            $colWidths = [10, 25, 20, 25, 15, 35, 15, 25, 20, 20, 20, 20];
            
            foreach ($headers as $index => $header) {
                $pdf->Cell($colWidths[$index], 7, $header, 1, 0, 'C', true);
            }
            $pdf->Ln();
            
            // Données du tableau
            $pdf->SetFont('helvetica', '', 8);
            $fill = false;
            
            foreach ($vehicles as $vehicle) {
                // Déterminer le nom du client selon son type
                $clientName = ($vehicle['type_client'] == 'Société' && !empty($vehicle['raison_sociale'])) 
                    ? $vehicle['raison_sociale'] 
                    : $vehicle['nom'] . ' ' . $vehicle['prenom'];
                
                $pdf->Cell($colWidths[0], 6, $vehicle['id'], 1, 0, 'C', $fill);
                $pdf->Cell($colWidths[1], 6, $vehicle['immatriculation'], 1, 0, 'L', $fill);
                $pdf->Cell($colWidths[2], 6, $vehicle['marque'], 1, 0, 'L', $fill);
                $pdf->Cell($colWidths[3], 6, $vehicle['modele'], 1, 0, 'L', $fill);
                $pdf->Cell($colWidths[4], 6, $vehicle['annee'], 1, 0, 'C', $fill);
                $pdf->Cell($colWidths[5], 6, $clientName, 1, 0, 'L', $fill);
                $pdf->Cell($colWidths[6], 6, $vehicle['type_client'], 1, 0, 'C', $fill);
                $pdf->Cell($colWidths[7], 6, $vehicle['telephone'], 1, 0, 'L', $fill);
                $pdf->Cell($colWidths[8], 6, $vehicle['kilometrage'] . ' km', 1, 0, 'R', $fill);
                $pdf->Cell($colWidths[9], 6, $vehicle['statut'], 1, 0, 'C', $fill);
                $pdf->Cell($colWidths[10], 6, $vehicle['couleur'], 1, 0, 'L', $fill);
                $pdf->Cell($colWidths[11], 6, $vehicle['carburant'], 1, 0, 'L', $fill);
                $pdf->Ln();
                
                $fill = !$fill;
            }
            
            // Générer le PDF
            $pdf->Output('vehicules_' . date('Y-m-d') . '.pdf', 'D');
        } else {
            // TCPDF n'est pas disponible
            $_SESSION['error_messages'] = ["La bibliothèque TCPDF n'est pas disponible. Veuillez installer les dépendances via Composer."];
            header('Location: view.php');
            exit;
        }
    } else {
        // Format non supporté
        $_SESSION['error_messages'] = ["Format d'export non supporté"];
        header('Location: view.php');
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['error_messages'] = ["Erreur lors de l'export: " . $e->getMessage()];
    header('Location: view.php');
    exit;
}
?>
