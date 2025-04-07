<?php
/**
 * Configuration de la base de données
 * Ce fichier gère la connexion à la base de données MySQL
 */

class Database {
    // Paramètres de connexion à la base de données
    private $host = "localhost";
    private $db_name = "sas_reparation_auto";
    private $username = "root";
    private $password = "passwd";
    public $conn;

    /**
     * Méthode pour se connecter à la base de données
     * @return PDO|null Retourne l'objet de connexion ou null en cas d'erreur
     */
    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            echo "Erreur de connexion: " . $exception->getMessage();
        }

        return $this->conn;
    }
}
?>