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

## 🚀 Installation

### Prérequis
- PHP 8.0 ou supérieur
- MySQL/MariaDB 5.7+
- Serveur web (Apache/Nginx)
- WAMP/XAMPP (pour développement local)

### Étapes d'Installation

1. **Cloner le repository**
   ```bash
   git clone https://github.com/votre-username/SmartSchoolHub.git
   cd SmartSchoolHub
   ```

2. **Configurer la base de données**
   - Importer le fichier `config/schema.sql`
   - Modifier `config/config.php` avec vos identifiants

3. **Exécuter le script d'initialisation**
   ```bash
   # Navigateur : http://localhost/SmartSchoolHub/scripts/perfect_college_final.php
   ```

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

## 📱 Captures d'Écran

*(Ajouter des captures d'écran ici)*

## 🔄 Mise à Jour

Pour mettre à jour le système :
1. Sauvegarder la base de données
2. Exécuter le script de mise à jour
3. Vérifier la compatibilité

## 🛡️ Sécurité

- Hashage des mots de passe avec PHP password_hash()
- Protection contre les injections SQL
- Gestion sécurisée des sessions
- Validation des entrées utilisateur

## 📝 Documentation

- [Guide d'installation](docs/installation.md)
- [Manuel utilisateur](docs/user-guide.md)
- [Documentation API](docs/api.md)
- [Guide développeur](docs/developer-guide.md)

## 🤝 Contribution

Les contributions sont les bienvenues ! Veuillez :
1. Fork le projet
2. Créer une branche feature
3. Soumettre une Pull Request

## 📄 Licence

Ce projet est sous licence MIT - voir le fichier [LICENSE](LICENSE) pour plus de détails.

## 👨‍💻 Auteur

**Développé par SmartSchool Team**
- Email : contact@smartschoolhub.com
- Site : www.smartschoolhub.com

## 🙏 Remerciements

- Bootstrap Team pour le framework CSS
- Font Awesome pour les icônes
- La communauté PHP pour l'inspiration

---

**🎓 SmartSchool Hub - La solution complète pour votre établissement scolaire**
