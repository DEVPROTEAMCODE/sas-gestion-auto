<?php
// Initialisation de la session
session_start();

// Si l'utilisateur est déjà connecté, le rediriger vers le tableau de bord
if(isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

// Inclusion des fichiers nécessaires
require_once "config/database.php";

// Variables pour les messages
$error_message = "";
$success_message = "";

// Traitement du formulaire de connexion
if($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    // Récupération des données du formulaire
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // Validation des données
    if(empty($username) || empty($password)) {
        $error_message = "Veuillez entrer votre nom d'utilisateur et votre mot de passe.";
    } else {
        // Connexion à la base de données
        $database = new Database();
        $db = $database->getConnection();
        
        // Variable pour suivre si l'utilisateur est trouvé
        $user_found = false;
        
        // 1. Vérifier d'abord dans la table users
        $query = "SELECT id, username, password, nom, prenom, email, role, actif 
                  FROM users
                  WHERE username = ? 
                  LIMIT 0,1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(1, $username);
        $stmt->execute();
        
        if($stmt->rowCount() > 0) {
            $user_found = true;
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Vérifier si l'utilisateur est actif
            if(!$user['actif']) {
                $error_message = "Ce compte est désactivé. Veuillez contacter l'administrateur.";
            } 
            // Vérifier le mot de passe
            else if(password_verify($password, $user['password'])) {
                // Stockage des informations utilisateur dans la session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['prenom'] . ' ' . $user['nom'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_type'] = 'admin'; // Marquer comme utilisateur administratif
                
                // Récupération des permissions de l'utilisateur
                $perm_query = "SELECT p.nom 
                              FROM permissions p
                              JOIN user_permissions up ON p.id = up.permission_id
                              WHERE up.user_id = ?";
                $perm_stmt = $db->prepare($perm_query);
                $perm_stmt->bindParam(1, $user['id']);
                $perm_stmt->execute();
                
                $_SESSION['user_permissions'] = [];
                while($perm = $perm_stmt->fetch(PDO::FETCH_ASSOC)) {
                    $_SESSION['user_permissions'][] = $perm['nom'];
                }
                
                // Mise à jour de la dernière connexion
                $update_query = "UPDATE users SET derniere_connexion = NOW() WHERE id = ?";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(1, $user['id']);
                $update_stmt->execute();
                
                // Journalisation de la connexion
                $log_query = "INSERT INTO logs (user_id, action, entite, entite_id, details, date_action, adresse_ip)
                             VALUES (?, 'Connexion', 'users', ?, 'Connexion réussie', NOW(), ?)";
                $log_stmt = $db->prepare($log_query);
                $log_stmt->bindParam(1, $user['id']);
                $log_stmt->bindParam(2, $user['id']);
                $log_stmt->bindParam(3, $_SERVER['REMOTE_ADDR']);
                $log_stmt->execute();
                
                // Redirection vers le tableau de bord
                header("Location: dashboard.php");
                exit;
            } else {
                $error_message = "Mot de passe incorrect.";
            }
        }
        
        // 2. Si l'utilisateur n'est pas trouvé dans la table users ou si le mot de passe est incorrect, 
        // chercher dans la table technicien
        if (!$user_found || $error_message == "Mot de passe incorrect.") {
            $query = "SELECT id, user_name, password, nom, prenom, email, specialite 
                      FROM technicien
                      WHERE user_name = ? 
                      LIMIT 0,1";
            $stmt = $db->prepare($query);
            $stmt->bindParam(1, $username);
            $stmt->execute();
            
            if($stmt->rowCount() > 0) {
                $technicien = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Vérifier le mot de passe
                if(password_verify($password, $technicien['password'])) {
                    // Stockage des informations technicien dans la session
                    $_SESSION['user_id'] = $technicien['id'];
                    $_SESSION['user_name'] = $technicien['prenom'] . ' ' . $technicien['nom'];
                    $_SESSION['username'] = $technicien['user_name'];
                    $_SESSION['user_email'] = $technicien['email'];
                    $_SESSION['user_role'] = 'technicien'; // Rôle fixe pour les techniciens
                    $_SESSION['user_type'] = 'technicien'; // Marquer comme technicien
                    $_SESSION['specialite'] = $technicien['specialite'];
                    
                    // Permissions de base pour les techniciens
                    $_SESSION['user_permissions'] = [
                        'lecture_interventions',
                        'lecture_vehicules',
                        'lecture_clients',
                        'gestion_interventions' // Permission pour modifier leurs interventions
                    ];
                    
                    // Journalisation de la connexion
                    $log_query = "INSERT INTO logs (action, entite, entite_id, details, date_action, adresse_ip)
                                 VALUES ('Connexion', 'technicien', ?, 'Connexion technicien réussie', NOW(), ?)";
                    $log_stmt = $db->prepare($log_query);
                    $log_stmt->bindParam(1, $technicien['id']);
                    $log_stmt->bindParam(2, $_SERVER['REMOTE_ADDR']);
                    $log_stmt->execute();
                    
                    // Redirection vers le tableau de bord
                    header("Location: dashboard.php");
                    exit;
                } else {
                    $error_message = "Mot de passe incorrect.";
                }
            } else {
                // Si l'utilisateur n'est pas trouvé dans la table technicien non plus
                if (!$user_found) {
                    $error_message = "Nom d'utilisateur inconnu.";
                }
            }
        }
    }
}

// Titre de la page
$page_title = "Connexion";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - SAS Réparation Véhicules</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .login-container {
            max-width: 400px;
            margin: 100px auto;
            padding: 20px;
            background-color: #fff;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo img {
            max-width: 200px;
        }
        .login-footer {
            text-align: center;
            margin-top: 20px;
            font-size: 0.9em;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="logo">
                <h2>SAS Réparation Véhicules</h2>
                <!-- <img src="assets/img/logo.png" alt="Logo SAS Réparation"> -->
            </div>
            
            <?php if(!empty($error_message)): ?>
                <div class="alert alert-danger"><?php echo $error_message; ?></div>
            <?php endif; ?>
            
            <?php if(!empty($success_message)): ?>
                <div class="alert alert-success"><?php echo $success_message; ?></div>
            <?php endif; ?>
            
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <div class="form-group">
                    <label for="username">Nom d'utilisateur</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                        </div>
                        <input type="text" class="form-control" id="username" name="username" required autofocus>
                    </div>
                </div>
                <div class="form-group">
                    <label for="password">Mot de passe</label>
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        </div>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                </div>
                <div class="form-group form-check">
                    <input type="checkbox" class="form-check-input" id="remember" name="remember">
                    <label class="form-check-label" for="remember">Se souvenir de moi</label>
                </div>
                <button type="submit" name="login" class="btn btn-primary btn-block">Se connecter</button>
            </form>
            
            <div class="login-footer">
                <p>&copy; <?php echo date('Y'); ?> SAS Réparation Véhicules - Tous droits réservés</p>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
