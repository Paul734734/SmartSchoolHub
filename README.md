<<<<<<< HEAD
# 🎓 SmartSchool Hub - Système de Gestion Scolaire Complet

## 📋 Description

SmartSchool Hub est une plateforme de gestion scolaire complète et moderne conçue pour les établissements d'enseignement secondaire. Elle offre une solution intégrée pour la gestion des élèves, enseignants, parents, notes, absences, paiements et communications.

## ✨ Fonctionnalités Principales

### 👥 Gestion Multi-Rôles
- **Super-Admin** : Administration complète du système
- **Admin** : Gestion quotidienne de l'établissement
- **Enseignant** : Gestion pédagogique et suivi des élèves
- **Parent** : Suivi scolaire des enfants
- **Élève** : Accès personnel aux informations scolaires

### 📊 Tableaux de Bord Standardisés
- Interface moderne et responsive
- Statistiques en temps réel
- Navigation fluide entre rôles
- Design cohérent sur tous les dashboards

### 🏫 Gestion Académique
- **Classes et Matières** : Configuration flexible
- **Évaluations** : Création et gestion des tests
- **Notes** : Saisie et calcul automatique des moyennes
- **Absences** : Suivi et reporting
- **Paiements** : Gestion des frais scolaires

### 💬 Communication
- **Messagerie interne** : Communication entre utilisateurs
- **Annonces** : Informations générales
- **Notifications** : Alertes automatiques

## 🛠️ Technologies Utilisées

- **Backend** : PHP 8.0+
- **Base de données** : MySQL/MariaDB
- **Frontend** : Bootstrap 5, Font Awesome 6
- **Architecture** : MVC Pattern
- **Sécurité** : Password hashing, Session management

## 📁 Structure du Projet

```
SmartSchoolHub/
├── dashboards/           # Tableaux de bord par rôle
│   ├── dashboard_superadmin_final.php
│   ├── dashboard_admin_perfect.php
│   ├── dashboard_teacher_perfect.php
│   ├── dashboard_parent_perfect.php
│   └── dashboard_student_perfect.php
├── scripts/              # Scripts de configuration
│   ├── perfect_college_final.php
│   └── generate_college_data.php
├── config/               # Configuration
│   ├── config.php
│   ├── database.php
│   └── schema.sql
├── includes/             # Fonctions utilitaires
├── pages/                # Pages spécifiques
├── assets/               # Ressources statiques
├── api/                  # Endpoints API
├── modules/              # Modules fonctionnels
├── temp/                 # Fichiers temporaires
├── legacy/               # Anciens fichiers
├── login_correct.php     # Page de connexion
├── logout.php            # Déconnexion
└── index.php             # Page d'accueil
```

## 🚀 Installation Rapide

### Prérequis
- PHP 8.0 ou supérieur
- MySQL/MariaDB 5.7+
- Serveur web (Apache/Nginx)
- WAMP/XAMPP (pour développement local)

### Étapes d'Installation

1. **Cloner le repository**
   ```bash
   git clone https://github.com/Paul734734/SmartSchoolHub.git
   cd SmartSchoolHub
   ```

2. **Configurer la base de données**
   - Importer le fichier `config/schema.sql`
   - Modifier `config/config.php` avec vos identifiants

3. **Exécuter le script d'initialisation**
   - Navigateur : `http://localhost/SmartSchoolHub/scripts/perfect_college_final.php`

4. **Accéder à l'application**
   - URL : `http://localhost/SmartSchoolHub/login_correct.php`

## 🔑 Comptes de Démo

### Super-Admin
- **Email** : admin@demo.com
- **Mot de passe** : password

### Enseignant
- **Email** : jean.mbarga@demo.com
- **Mot de passe** : Teacher123!

### Parent
- **Email** : parent.joseph@demo.com
- **Mot de passe** : Parent123!

### Élève
- **Email** : paul.nkamga@demo.com
- **Mot de passe** : Student123!

## 🛡️ Sécurité

- Hashage des mots de passe avec PHP password_hash()
- Protection contre les injections SQL
- Gestion sécurisée des sessions
- Validation des entrées utilisateur

## 📝 Documentation

- Voir `git_setup.md` pour le guide de push GitHub
- Documentation détaillée dans le dossier `docs/`

## 🤝 Contribution

Les contributions sont les bienvenues ! Veuillez :
1. Fork le projet
2. Créer une branche feature
3. Soumettre une Pull Request

## 📄 Licence

Ce projet est sous licence MIT.

## 👨‍💻 Auteur

**Développé par SmartSchool Team**

---

**🎓 SmartSchool Hub - La solution complète pour votre établissement scolaire**
├── index.php                  ← Point d'entrée (redirige selon le rôle)
├── login.php                  ← Page de connexion
├── logout.php                 ← Déconnexion
│
├── config/
│   ├── config.php             ← Configuration générale (générée par l'installeur)
│   ├── database.php           ← Classe Database (connexion PDO)
│   └── schema.sql             ← Structure de la base de données
│
├── includes/
│   ├── auth.php               ← Classe Auth (sessions, rôles)
│   ├── functions.php          ← Fonctions globales
│   ├── header.php             ← En-tête HTML réutilisable
│   ├── sidebar.php            ← Barre de navigation latérale
│   └── footer.php             ← Pied de page HTML
│
├── pages/
│   ├── admin/
│   │   └── dashboard.php      ← Tableau de bord Administrateur
│   ├── teacher/
│   │   ├── dashboard.php      ← Tableau de bord Enseignant
│   │   ├── grades.php         ← Saisie des notes
│   │   └── attendance.php     ← Gestion des présences
│   ├── student/
│   │   └── dashboard.php      ← Tableau de bord Élève
│   └── parent/
│       └── dashboard.php      ← Tableau de bord Parent
│
├── assets/
│   ├── css/
│   │   └── style.css          ← Feuille de styles principale
│   └── js/
│       └── app.js             ← Scripts JavaScript
│
├── uploads/
│   ├── avatars/               ← Photos de profil
│   └── documents/             ← Documents téléversés
│
└── logs/
    └── error.log              ← Journal d'erreurs (créé automatiquement)
```

## 🚀 Installation rapide

1. Déposez le dossier `SmartSchoolHub/` sur votre serveur web (Apache/Nginx + PHP 8+)
2. Ouvrez `http://votre-domaine.com/SmartSchoolHub/install.php`
3. Suivez les 3 étapes de l'installeur
4. **Supprimez `install.php` après installation**

## ⚙️ Pré-requis serveur

- PHP ≥ 8.0 (extensions : pdo, pdo_mysql, mbstring, json, openssl)
- MySQL ≥ 5.7 ou MariaDB ≥ 10.3
- Serveur Apache ou Nginx

## 🌍 Timezone

Configuré par défaut sur `Africa/Douala` (Cameroun).
Modifiable dans `config/config.php` → `date_default_timezone_set(...)`.
>>>>>>> 65bb57c18ac64a611922d93bbca39ae13b3bcf63
