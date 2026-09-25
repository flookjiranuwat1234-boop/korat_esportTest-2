-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: esport_korattest
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `accommodations`
--

DROP TABLE IF EXISTS `accommodations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accommodations` (
  `accommodation_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tournament_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `distance` varchar(50) DEFAULT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`accommodation_id`),
  KEY `tournament_id` (`tournament_id`),
  CONSTRAINT `accommodations_ibfk_1` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`tournament_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `accommodations`
--

LOCK TABLES `accommodations` WRITE;
/*!40000 ALTER TABLE `accommodations` DISABLE KEYS */;
INSERT INTO `accommodations` VALUES (1,NULL,'α╣éα╕úα╕çα╣üα╕úα╕íα╕¬α╕╡α╕íα╕▓α╕ÿα╕▓α╕Öα╕╡ (Sima Thani Hotel)','2112/2 α╕û.α╕íα╕┤α╕òα╕úα╕áα╕▓α╕₧ α╕ò.α╣âα╕Öα╣Çα╕íα╕╖α╕¡α╕ç α╕¡.α╣Çα╕íα╕╖α╕¡α╕ç α╕ê.α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓ 30000',NULL,NULL,'https://www.google.com/maps/search/?api=1&query=α╣éα╕úα╕çα╣üα╕úα╕íα╕¬α╕╡α╕íα╕▓α╕ÿα╕▓α╕Öα╕╡+α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓'),(4,NULL,'B2 Korat Premier Hotel','1688, 2 α╕û. α╕íα╕┤α╕òα╕úα╕áα╕▓α╕₧ α╕òα╕│α╕Üα╕Ñα╣âα╕Öα╣Çα╕íα╕╖α╕¡α╕ç α╕¡α╕│α╣Çα╕áα╕¡α╣Çα╕íα╕╖α╕¡α╕çα╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓ α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓ 30000',NULL,NULL,'https://maps.app.goo.gl/ogCFHkmr6pafEF3c8');
/*!40000 ALTER TABLE `accommodations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bracket_edges`
--

DROP TABLE IF EXISTS `bracket_edges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bracket_edges` (
  `bracket_edge_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `match_id` int(10) unsigned NOT NULL,
  `next_match_id` int(10) unsigned DEFAULT NULL,
  `next_slot` varchar(10) DEFAULT NULL,
  `loser_next_match_id` bigint(20) unsigned DEFAULT NULL,
  `loser_next_slot` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`bracket_edge_id`),
  UNIQUE KEY `uq_match_edge` (`match_id`),
  KEY `fk_bracket_edges_next_match` (`next_match_id`),
  CONSTRAINT `bracket_edges_ibfk_1` FOREIGN KEY (`match_id`) REFERENCES `matches` (`match_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bracket_edges_next_match` FOREIGN KEY (`next_match_id`) REFERENCES `matches` (`match_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=319 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bracket_edges`
--

LOCK TABLES `bracket_edges` WRITE;
/*!40000 ALTER TABLE `bracket_edges` DISABLE KEYS */;
/*!40000 ALTER TABLE `bracket_edges` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gallery`
--

DROP TABLE IF EXISTS `gallery`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gallery` (
  `gallery_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `album_id` int(11) DEFAULT NULL,
  `media_type` varchar(20) NOT NULL DEFAULT 'activity',
  `title` varchar(255) DEFAULT NULL,
  `image_path` varchar(255) NOT NULL,
  `caption` varchar(200) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `event_name` varchar(150) DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`gallery_id`),
  KEY `created_by` (`created_by`),
  KEY `album_id` (`album_id`),
  CONSTRAINT `gallery_album_fk` FOREIGN KEY (`album_id`) REFERENCES `gallery_albums` (`album_id`) ON DELETE SET NULL,
  CONSTRAINT `gallery_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=60 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gallery`
--

LOCK TABLES `gallery` WRITE;
/*!40000 ALTER TABLE `gallery` DISABLE KEYS */;
INSERT INTO `gallery` VALUES (34,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_2080.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(35,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_7569.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(36,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_4729.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(37,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_1922.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(38,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_5457.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(39,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_2571.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(40,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_2599.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(41,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_5590.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(42,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_7101.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(43,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_6643.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(44,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_3868.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(45,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_3444.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(46,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_7449.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(47,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_7965.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(48,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_1161.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(49,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_9554.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(50,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_2414.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(51,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_5455.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(52,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_8275.jpg','',1,NULL,1,'2026-08-13 21:26:58'),(53,6,'activity',NULL,'uploads/gallery/album_6/img_1786631218_4557.jpg','',1,NULL,1,'2026-08-13 21:26:58');
/*!40000 ALTER TABLE `gallery` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gallery_albums`
--

DROP TABLE IF EXISTS `gallery_albums`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gallery_albums` (
  `album_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `album_type` varchar(20) NOT NULL DEFAULT 'activity',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`album_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gallery_albums`
--

LOCK TABLES `gallery_albums` WRITE;
/*!40000 ALTER TABLE `gallery_albums` DISABLE KEYS */;
INSERT INTO `gallery_albums` VALUES (6,'α╕êα╕Üα╣äα╕¢α╣üα╕Ñα╣ëα╕ºα╕üα╕▒α╕Üα╕üα╕▓α╕úα╣üα╕éα╣êα╕çα╕éα╕▒α╕Öα╕¬α╕╕α╕öα╕íα╕▒α╕Öα╕¬α╣î #α╕ùα╕╡α╣êα╕¬α╕╕α╕öα╕éα╕¡α╕çα╣Çα╕üα╕íα╕¬α╣î   α╕íα╕½α╕üα╕úα╕úα╕íα╣Çα╕üα╕íα╕¬α╣îα╕ùα╕╡α╣êα╣âα╕½α╕ìα╣êα╕ùα╕╡α╣êα╕¬α╕╕α╕öα╕úα╕ºα╕íα╣Çα╕üα╕íα╕¬α╣îα╣Çα╕óα╕¡α╕░α╕ùα╕╡α╣êα╕¬α╕╕α╕öα╣âα╕Öα╕¡α╕╡α╕¬α╕▓α╕Ö Terminal21 Games Festival 2026 ≡ƒôìα╣Çα╕ùα╕¡α╕úα╣îα╕íα╕┤α╕Öα╕¡α╕Ñα╕«α╕¡α╕Ñα╕Ñα╣î α╕èα╕▒α╣ëα╕Ö 4 -α╕ïα╕▓α╕Öα╕ƒα╕úα╕▓α╕Öα╕ïα╕┤α╕¬α╣éα╕ü #α╣Çα╕ùα╕¡α╕úα╣îα╕íα╕┤α╕Öα╕¡α╕Ñ21α╣éα╕äα╕úα╕▓α╕è','','activity','2026-08-13 14:26:58'),(8,'α╕üα╕▓α╕úα╕üα╕úα╕¡α╕çα╕éα╣êα╕▓α╕º','','banner','2026-08-20 15:43:44');
/*!40000 ALTER TABLE `gallery_albums` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `games`
--

DROP TABLE IF EXISTS `games`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `games` (
  `game_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `slug` varchar(80) NOT NULL,
  `icon_path` varchar(255) DEFAULT NULL,
  `roster_size_min` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `roster_size_max` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `is_team_based` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `play_mode` enum('solo','team') NOT NULL DEFAULT 'team',
  `required_players` int(11) NOT NULL DEFAULT 5,
  `ranking_config` text DEFAULT NULL COMMENT 'α╕üα╕òα╕┤α╕üα╕▓α╣üα╕Ñα╕░α╕¬α╕╣α╕òα╕ú Ranking α╕éα╕¡α╕çα╣Çα╕üα╕í α╕üα╕│α╕½α╕Öα╕öα╣éα╕öα╕ó Admin',
  PRIMARY KEY (`game_id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `games`
--

LOCK TABLES `games` WRITE;
/*!40000 ALTER TABLE `games` DISABLE KEYS */;
INSERT INTO `games` VALUES (2,'Arena of Valor (RoV) - α╕úα╕╕α╣êα╕Öα╕¡α╕▓α╕óα╕╕α╕òα╣êα╕│α╕üα╕ºα╣êα╕▓ 18 α╕¢α╕╡','',NULL,5,5,1,1,'2026-08-17 20:53:23','team',5,NULL),(39,'Arena of Valor (RoV) - α╕úα╕╕α╣êα╕Öα╕¡α╕▓α╕óα╕╕α╕òα╣êα╕│α╕üα╕ºα╣êα╕▓ 18 α╕¢α╕╡','rov-u18',NULL,5,5,1,1,'2026-08-17 21:13:11','team',5,NULL),(40,'Arena of Valor (RoV) - α╕úα╕╕α╣êα╕Ö Open','rov-open',NULL,5,5,1,1,'2026-08-17 21:13:11','team',5,NULL),(41,'Free Fire - α╕úα╕╕α╣êα╕Ö Open','free-fire-open',NULL,1,5,1,1,'2026-08-17 21:13:11','team',5,NULL),(42,'Tekken 8 - α╕úα╕╕α╣êα╕Ö Open','tekken-8-open',NULL,1,1,0,1,'2026-08-17 21:13:11','solo',1,NULL),(43,'Street Fighter 6 - α╕úα╕╕α╣êα╕Ö Open','street-fighter-6-open',NULL,1,1,0,1,'2026-08-17 21:13:11','solo',1,NULL),(44,'Efootball Mobile - α╕úα╕╕α╣êα╕Ö Open','efootball-mobile-open',NULL,1,1,0,1,'2026-08-17 21:13:11','solo',1,NULL),(45,'Roblox - α╕úα╕╕α╣êα╕Öα╕¡α╕▓α╕óα╕╕ 8-12 α╕¢α╕╡','roblox-u12',NULL,1,1,0,1,'2026-08-17 21:13:11','solo',1,NULL);
/*!40000 ALTER TABLE `games` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `group_teams`
--

DROP TABLE IF EXISTS `group_teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `group_teams` (
  `group_team_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int(10) unsigned NOT NULL,
  `team_id` int(10) unsigned NOT NULL,
  `played` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `wins` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `draws` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `losses` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `points` smallint(6) NOT NULL DEFAULT 0,
  `score_diff` smallint(6) NOT NULL DEFAULT 0,
  PRIMARY KEY (`group_team_id`),
  UNIQUE KEY `uq_team_per_group` (`group_id`,`team_id`),
  KEY `team_id` (`team_id`),
  CONSTRAINT `group_teams_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `tournament_groups` (`tournament_group_id`) ON DELETE CASCADE,
  CONSTRAINT `group_teams_ibfk_2` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `group_teams`
--

LOCK TABLES `group_teams` WRITE;
/*!40000 ALTER TABLE `group_teams` DISABLE KEYS */;
/*!40000 ALTER TABLE `group_teams` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `match_games`
--

DROP TABLE IF EXISTS `match_games`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `match_games` (
  `game_id` int(11) NOT NULL AUTO_INCREMENT,
  `match_id` int(11) NOT NULL,
  `game_number` int(11) NOT NULL,
  `team1_score` int(11) DEFAULT 0,
  `team2_score` int(11) DEFAULT 0,
  `winner_team_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`game_id`),
  KEY `match_id` (`match_id`)
) ENGINE=InnoDB AUTO_INCREMENT=284 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `match_games`
--

LOCK TABLES `match_games` WRITE;
/*!40000 ALTER TABLE `match_games` DISABLE KEYS */;
INSERT INTO `match_games` VALUES (272,418,1,3,0,2,'2026-08-21 13:34:55'),(273,418,2,1,3,3,'2026-08-21 13:34:55'),(274,418,3,2,3,3,'2026-08-21 13:34:55'),(275,419,1,2,3,4,'2026-08-21 13:35:06'),(276,419,2,2,3,4,'2026-08-21 13:35:06'),(277,419,3,3,1,1,'2026-08-21 13:35:06'),(278,421,1,3,2,6,'2026-08-21 13:35:21'),(279,421,2,3,1,6,'2026-08-21 13:35:21'),(280,421,3,3,1,6,'2026-08-21 13:35:21'),(281,420,1,2,3,4,'2026-08-21 13:35:38'),(282,420,2,2,3,4,'2026-08-21 13:35:38'),(283,420,3,3,2,3,'2026-08-21 13:35:38');
/*!40000 ALTER TABLE `match_games` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `match_participants`
--

DROP TABLE IF EXISTS `match_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `match_participants` (
  `match_participant_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `match_id` int(10) unsigned NOT NULL,
  `team_id` int(10) unsigned NOT NULL,
  `slot_number` smallint(5) unsigned DEFAULT NULL,
  `placement` smallint(5) unsigned DEFAULT NULL,
  `kills` smallint(5) unsigned NOT NULL DEFAULT 0,
  `score` int(11) NOT NULL DEFAULT 0,
  `is_winner` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('scheduled','completed','bye','walkover','forfeit','disqualified') NOT NULL DEFAULT 'scheduled',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`match_participant_id`),
  UNIQUE KEY `uq_match_team` (`match_id`,`team_id`),
  KEY `team_id` (`team_id`),
  KEY `idx_match_placement` (`match_id`,`placement`),
  CONSTRAINT `match_participants_match_fk` FOREIGN KEY (`match_id`) REFERENCES `matches` (`match_id`) ON DELETE CASCADE,
  CONSTRAINT `match_participants_team_fk` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `match_participants`
--

LOCK TABLES `match_participants` WRITE;
/*!40000 ALTER TABLE `match_participants` DISABLE KEYS */;
/*!40000 ALTER TABLE `match_participants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `matches`
--

DROP TABLE IF EXISTS `matches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `matches` (
  `match_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tournament_id` int(10) unsigned NOT NULL,
  `tournament_category_id` int(10) unsigned DEFAULT NULL,
  `group_id` int(10) unsigned DEFAULT NULL,
  `bracket_type` enum('single','winners','losers','grand_final','grand_final_reset') NOT NULL DEFAULT 'single',
  `reset_match_id` int(11) DEFAULT NULL,
  `best_of` tinyint(4) NOT NULL DEFAULT 1,
  `round_number` tinyint(3) unsigned DEFAULT NULL,
  `match_index` smallint(5) unsigned DEFAULT NULL,
  `team1_id` int(10) unsigned DEFAULT NULL,
  `team2_id` int(10) unsigned DEFAULT NULL,
  `team1_score` smallint(5) unsigned DEFAULT NULL,
  `team2_score` smallint(5) unsigned DEFAULT NULL,
  `winner_team_id` int(10) unsigned DEFAULT NULL,
  `result_type` enum('normal','bye','walkover','forfeit','disqualified') NOT NULL DEFAULT 'normal',
  `result_note` varchar(500) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'scheduled',
  `scheduled_at` datetime DEFAULT NULL,
  `competition_area` varchar(100) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `wo_reason` varchar(500) DEFAULT NULL,
  `venue_name` varchar(255) DEFAULT NULL,
  `venue_area` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`match_id`),
  KEY `tournament_id` (`tournament_id`),
  KEY `tournament_category_id` (`tournament_category_id`),
  KEY `group_id` (`group_id`),
  KEY `team1_id` (`team1_id`),
  KEY `team2_id` (`team2_id`),
  KEY `winner_team_id` (`winner_team_id`),
  CONSTRAINT `matches_category_fk` FOREIGN KEY (`tournament_category_id`) REFERENCES `tournament_categories` (`tournament_category_id`) ON DELETE CASCADE,
  CONSTRAINT `matches_ibfk_1` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`tournament_id`) ON DELETE CASCADE,
  CONSTRAINT `matches_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `tournament_groups` (`tournament_group_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=422 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `matches`
--

LOCK TABLES `matches` WRITE;
/*!40000 ALTER TABLE `matches` DISABLE KEYS */;
/*!40000 ALTER TABLE `matches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `news`
--

DROP TABLE IF EXISTS `news`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `news` (
  `news_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `content` text NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `status` enum('published','draft') NOT NULL DEFAULT 'published',
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`news_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `news_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `news`
--

LOCK TABLES `news` WRITE;
/*!40000 ALTER TABLE `news` DISABLE KEYS */;
INSERT INTO `news` VALUES (2,'α╕éα╕¡α╣üα╕¬α╕öα╕çα╕äα╕ºα╕▓α╕íα╕óα╕┤α╕Öα╕öα╕╡α╕üα╕▒α╕Üα╕Öα╕▒α╕üα╕üα╕╡α╕¼α╕▓α╕úα╕╕α╣êα╕Öα╣Çα╕óα╕▓α╕ºα╕èα╕Ö α╕áα╕▓α╕óα╣âα╕òα╣ëα╕¬α╕▒α╕çα╕üα╕▒α╕öα╕¬α╣éα╕íα╕¬α╕úα╕¡α╕╡α╕¬α╕¢α╕¡α╕úα╣îα╕òα╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','≡ƒÄëα╕éα╕¡α╣üα╕¬α╕öα╕çα╕äα╕ºα╕▓α╕íα╕óα╕┤α╕Öα╕öα╕╡α╕üα╕▒α╕Üα╕Öα╕▒α╕üα╕üα╕╡α╕¼α╕▓α╕úα╕╕α╣êα╕Öα╣Çα╕óα╕▓α╕ºα╕èα╕Ö α╕áα╕▓α╕óα╣âα╕òα╣ëα╕¬α╕▒α╕çα╕üα╕▒α╕öα╕¬α╣éα╕íα╕¬α╕úα╕¡α╕╡α╕¬α╕¢α╕¡α╕úα╣îα╕òα╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓≡ƒÄë\r\nα╕Öα╕▓α╕óα╣Çα╕òα╕èα╕¬α╕┤α╕ùα╕ÿα╕┤α╣î α╕¡α╕áα╕┤α╕èα╕┤α╕òα╕¿α╕▒α╕üα╕öα╕┤α╣îα╕èα╕▒α╕ó α╕êα╕▓α╕ü Plookpanya School - α╣éα╕úα╕çα╣Çα╕úα╕╡α╕óα╕Öα╕¢α╕Ñα╕╣α╕üα╕¢α╕▒α╕ìα╕ìα╕▓ α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓ \r\nα╣üα╕Ñα╕░ α╣Çα╕öα╣çα╕üα╕½α╕ìα╕┤α╕çα╕íα╕┤α╕ôα╕ùα╕úα╕▓α╕öα╕▓ α╕íα╕╡α╣âα╕½α╕íα╣ê α╕êα╕▓α╕ü α╣éα╕úα╕çα╣Çα╕úα╕╡α╕óα╕Öα╣Çα╕ùα╕¿α╕Üα╕▓α╕Ñ1-α╕Üα╕╣α╕úα╕₧α╕▓α╕ºα╕┤α╕ùα╕óα╕▓α╕üα╕ú \r\nα╕ùα╕╡α╣êα╕¬α╕▓α╕íα╕▓α╕úα╕ûα╕ùα╕│α╕£α╕Ñα╕çα╕▓α╕Öα╣äα╕öα╣ëα╕¡α╕óα╣êα╕▓α╕çα╕óα╕¡α╕öα╣Çα╕óα╕╡α╣êα╕óα╕í α╕äα╕ºα╣ëα╕▓ 3 α╣Çα╕½α╕úα╕╡α╕óα╕ìα╕ùα╕¡α╕çα╕êα╕▓α╕üα╕çα╕▓α╕Öα╣üα╕éα╣êα╕çα╕éα╕▒α╕Öα╕üα╕╡α╕¼α╕▓α╣Çα╕óα╕▓α╕ºα╕èα╕Öα╣Çα╣Çα╕½α╣êα╕çα╕èα╕▓α╕òα╕┤α╕äα╕úα╕▒α╣ëα╕çα╕ùα╕╡α╣ê 41 α╕úα╕¡α╕Üα╕äα╕▒α╕öα╣Çα╕Ñα╕╖α╕¡α╕üα╕òα╕▒α╕ºα╣Çα╣Çα╕ùα╕Öα╕áα╕▓α╕ä 3 \"α╕½α╕Öα╕¡α╕çα╕½α╕▓α╕úα╣Çα╕üα╕íα╕¬α╣î\" α╕èα╕Öα╕┤α╕öα╕üα╕╡α╕¼α╕▓ α╕¡α╕╡α╕¬α╕¢α╕¡α╕úα╣îα╕ò Street Fighter 6 α╕¢α╕úα╕░α╣Çα╕áα╕ù α╕èα╕▓α╕óα╣Çα╕öα╕╡α╣êα╕óα╕º α╕½α╕ìα╕┤α╕çα╣Çα╕öα╕╡α╣êα╕óα╕º α╣Çα╣Çα╕Ñα╕░ α╕äα╕╣α╣êα╕£α╕¬α╕í α╣Çα╕íα╕╖α╣êα╕¡α╕ºα╕▒α╕Öα╕ùα╕╡α╣ê 6 - 10 α╕íα╕üα╕úα╕▓α╕äα╕í 2569\r\n.\r\nα╕½α╕Ñα╕▒α╕çα╕êα╕▓α╕üα╕Öα╕╡α╣ëα╕Öα╕▒α╕üα╕üα╕╡α╕¼α╕▓α╕ùα╕▒α╣ëα╕çα╕¬α╕¡α╕çα╕äα╕Öα╕êα╕░α╣äα╕öα╣ëα╣äα╕¢α╣üα╕éα╣êα╕çα╕òα╣êα╕¡α╕úα╕¡α╕Üα╕úα╕░α╕öα╕▒α╕Üα╕¢α╕úα╕░α╣Çα╕ùα╕¿α╕ùα╕╡α╣êα╕êα╕▒α╕çα╕½α╕ºα╕▒α╕öα╕¬α╕╕α╕úα╕▓α╕⌐α╕Äα╕úα╣îα╕ÿα╕▓α╕Öα╕╡ α╣âα╕Öα╣Çα╕öα╕╖α╕¡α╕Öα╕₧α╕ñα╕⌐α╕áα╕▓α╕äα╕í 2569 α╕¥α╕▓α╕üα╕₧α╕╡α╣êα╕Öα╣ëα╕¡α╕çα╕èα╕▓α╕ºα╣éα╕äα╕úα╕▓α╕èα╕¬α╣êα╕çα╣üα╕úα╕çα╣Çα╕èα╕╡α╕óα╕úα╣îα╣âα╕½α╣ëα╕Öα╕▒α╕üα╕üα╕╡α╕¼α╕▓α╕ùα╕▒α╣ëα╕çα╕¬α╕¡α╕çα╕äα╕Öα╕öα╣ëα╕ºα╕óα╕Öα╕░α╕äα╕úα╕▒α╕Ü/α╕äα╣êα╕░\r\n#streetfighter6 #tesf #α╕½α╕Öα╕¡α╕çα╕½α╕▓α╕úα╣Çα╕üα╕íα╕¬α╣î #α╕üα╕╡α╕¼α╕▓α╣Çα╕óα╕▓α╕ºα╕èα╕Öα╣üα╕½α╣êα╕çα╕èα╕▓α╕òα╕┤α╕äα╕úα╕▒α╣ëα╕çα╕ùα╕╡α╣ê41 #α╕¬α╕üα╕Ñα╕Öα╕äα╕ú #α╣éα╕äα╕úα╕▓α╕è #α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓ #α╕¬α╕íα╕▓α╕äα╕íα╕¡α╕╡α╕¬α╕¢α╕¡α╕úα╣îα╕òα╣üα╕½α╣êα╕çα╕¢α╕úα╕░α╣Çα╕ùα╕¿α╣äα╕ùα╕ó','uploads/news/6a62213b492ef_1784815931.jpg','published',1,'2026-07-23 21:10:54'),(4,'α╕êα╕Üα╣äα╕¢α╣üα╕Ñα╣ëα╕ºα╕üα╕▒α╕Üα╕üα╕▓α╕úα╣üα╕éα╣êα╕çα╕éα╕▒α╕Öα╕¬α╕╕α╕öα╕íα╕▒α╕Öα╕¬α╣î #α╕ùα╕╡α╣êα╕¬α╕╕α╕öα╕éα╕¡α╕çα╣Çα╕üα╕íα╕¬α╣î   α╕íα╕½α╕üα╕úα╕úα╕íα╣Çα╕üα╕íα╕¬α╣îα╕ùα╕╡α╣êα╣âα╕½α╕ìα╣êα╕ùα╕╡α╣êα╕¬α╕╕α╕öα╕úα╕ºα╕íα╣Çα╕üα╕íα╕¬α╣îα╣Çα╕óα╕¡α╕░α╕ùα╕╡α╣êα╕¬α╕╕α╕öα╣âα╕Öα╕¡α╕╡α╕¬α╕▓α╕Ö Terminal21 Games Festival 2026 ≡ƒôìα╣Çα╕ùα╕¡α╕úα╣îα╕íα╕┤α╕Öα╕¡α╕Ñα╕«α╕¡α╕Ñα╕Ñα╣î α╕èα╕▒α╣ëα╕Ö 4 -α╕ïα╕▓α╕Öα╕ƒα╕úα╕▓α╕Öα╕ïα╕┤α╕¬α╣éα╕ü #α╣Çα╕ùα╕¡α╕úα╣îα╕íα╕┤α╕Öα╕¡α╕Ñ21α╣éα╕äα╕úα╕▓α╕è','Terminal21 Games Festival 2026','uploads/news/6a7dd48a94d51_1786631306.jpg','published',1,'2026-08-13 21:28:26'),(5,'≡ƒÅå α╕Üα╕▒α╕Ñα╕Ñα╕▒α╕çα╕üα╣îα╕¡α╕╡α╕¬α╕▓α╕Öα╕¬α╕▒α╣êα╕Öα╕¬α╕░α╣Çα╕ùα╕╖α╕¡α╕Ö! \"FIVE FLOW 7\" α╕£α╕çα╕▓α╕öα╕äα╕ºα╣ëα╕▓α╣üα╕èα╕íα╕¢α╣î ROV ESAN ESPORTS OPEN 2026!','ESAN ESPORTS OPEN 2026','uploads/news/6a7dd73cd36c7_1786631996.jpg','published',1,'2026-08-13 21:39:56'),(6,'TEST NEWS','ssss','uploads/news/6a880d0a915d1_1787301130.jpg','published',1,'2026-08-21 15:32:10');
/*!40000 ALTER TABLE `news` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `player_checkin_history`
--

DROP TABLE IF EXISTS `player_checkin_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `player_checkin_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tournament_registration_member_id` int(11) DEFAULT NULL,
  `player_id` int(11) NOT NULL,
  `tournament_id` int(11) DEFAULT NULL,
  `checked_in_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `player_tournament` (`player_id`,`tournament_id`),
  KEY `tournament_registration_member_id` (`tournament_registration_member_id`),
  CONSTRAINT `checkin_registration_member_fk` FOREIGN KEY (`tournament_registration_member_id`) REFERENCES `tournament_registration_members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `player_checkin_history`
--

LOCK TABLES `player_checkin_history` WRITE;
/*!40000 ALTER TABLE `player_checkin_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `player_checkin_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `player_rankings`
--

DROP TABLE IF EXISTS `player_rankings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `player_rankings` (
  `player_ranking_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `game_id` int(10) unsigned NOT NULL,
  `player_id` int(10) unsigned NOT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'open',
  `points` int(11) NOT NULL DEFAULT 0,
  `matches_played` smallint(5) unsigned NOT NULL DEFAULT 0,
  `wins` smallint(5) unsigned NOT NULL DEFAULT 0,
  `losses` smallint(5) unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`player_ranking_id`),
  UNIQUE KEY `uq_player_ranking` (`game_id`,`player_id`,`category`),
  KEY `player_id` (`player_id`),
  CONSTRAINT `player_rankings_ibfk_1` FOREIGN KEY (`game_id`) REFERENCES `games` (`game_id`) ON DELETE CASCADE,
  CONSTRAINT `player_rankings_ibfk_2` FOREIGN KEY (`player_id`) REFERENCES `players` (`player_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `player_rankings`
--

LOCK TABLES `player_rankings` WRITE;
/*!40000 ALTER TABLE `player_rankings` DISABLE KEYS */;
INSERT INTO `player_rankings` VALUES (1,2,11,'male',3,2,1,1,'2026-08-21 20:35:38'),(2,2,12,'male',3,2,1,1,'2026-08-21 20:35:38'),(3,2,13,'male',3,2,1,1,'2026-08-21 20:35:38'),(4,2,14,'male',3,2,1,1,'2026-08-21 20:35:38'),(5,2,15,'male',3,2,1,1,'2026-08-21 20:35:38'),(6,2,6,'male',0,1,0,1,'2026-08-21 20:34:55'),(7,2,7,'male',0,1,0,1,'2026-08-21 20:34:55'),(8,2,8,'male',0,1,0,1,'2026-08-21 20:34:55'),(9,2,9,'male',0,1,0,1,'2026-08-21 20:34:55'),(10,2,10,'male',0,1,0,1,'2026-08-21 20:34:55'),(11,2,16,'male',6,2,2,0,'2026-08-21 20:35:38'),(12,2,17,'male',6,2,2,0,'2026-08-21 20:35:38'),(13,2,18,'male',6,2,2,0,'2026-08-21 20:35:38'),(14,2,19,'male',6,2,2,0,'2026-08-21 20:35:38'),(15,2,20,'male',6,2,2,0,'2026-08-21 20:35:38'),(16,2,1,'male',0,1,0,1,'2026-08-21 20:35:06'),(17,2,2,'male',0,1,0,1,'2026-08-21 20:35:06'),(18,2,3,'male',0,1,0,1,'2026-08-21 20:35:06'),(19,2,4,'male',0,1,0,1,'2026-08-21 20:35:06'),(20,2,5,'male',0,1,0,1,'2026-08-21 20:35:06'),(21,2,26,'female',3,1,1,0,'2026-08-21 20:35:21'),(22,2,27,'female',3,1,1,0,'2026-08-21 20:35:21'),(23,2,28,'female',3,1,1,0,'2026-08-21 20:35:21'),(24,2,29,'female',3,1,1,0,'2026-08-21 20:35:21'),(25,2,30,'female',3,1,1,0,'2026-08-21 20:35:21'),(26,2,21,'female',0,1,0,1,'2026-08-21 20:35:21'),(27,2,22,'female',0,1,0,1,'2026-08-21 20:35:21'),(28,2,23,'female',0,1,0,1,'2026-08-21 20:35:21'),(29,2,24,'female',0,1,0,1,'2026-08-21 20:35:21'),(30,2,25,'female',0,1,0,1,'2026-08-21 20:35:21');
/*!40000 ALTER TABLE `player_rankings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `player_tournament_checkins`
--

DROP TABLE IF EXISTS `player_tournament_checkins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `player_tournament_checkins` (
  `player_tournament_checkin_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tournament_registration_id` int(10) unsigned NOT NULL,
  `player_id` int(10) unsigned NOT NULL,
  `checkin_status` varchar(30) NOT NULL DEFAULT 'not_checked_in',
  `checked_in_at` datetime DEFAULT NULL,
  `checked_in_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`player_tournament_checkin_id`),
  UNIQUE KEY `player_registration_checkin_unique` (`tournament_registration_id`,`player_id`),
  KEY `player_checkin_player_idx` (`player_id`),
  KEY `player_checkin_user_fk` (`checked_in_by`),
  CONSTRAINT `player_checkin_player_fk` FOREIGN KEY (`player_id`) REFERENCES `players` (`player_id`),
  CONSTRAINT `player_checkin_registration_fk` FOREIGN KEY (`tournament_registration_id`) REFERENCES `tournament_registrations` (`tournament_registration_id`) ON DELETE CASCADE,
  CONSTRAINT `player_checkin_user_fk` FOREIGN KEY (`checked_in_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=112 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `player_tournament_checkins`
--

LOCK TABLES `player_tournament_checkins` WRITE;
/*!40000 ALTER TABLE `player_tournament_checkins` DISABLE KEYS */;
/*!40000 ALTER TABLE `player_tournament_checkins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `players`
--

DROP TABLE IF EXISTS `players`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `players` (
  `player_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `display_name` varchar(80) NOT NULL,
  `real_name` varchar(120) DEFAULT NULL,
  `gender` varchar(50) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `eligibility_status` enum('unverified','pending','verified','rejected') NOT NULL DEFAULT 'unverified',
  `show_real_name` tinyint(1) NOT NULL DEFAULT 0,
  `avatar_path` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `province` varchar(80) DEFAULT 'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`player_id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `eligibility_status` (`eligibility_status`),
  CONSTRAINT `players_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `players`
--

LOCK TABLES `players` WRITE;
/*!40000 ALTER TABLE `players` DISABLE KEYS */;
INSERT INTO `players` VALUES (1,88,'Athlete 01','Test Athlete 01','male','2000-01-01','unverified',0,'uploads/players/player_1_1787300920.jpg','','α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(2,89,'Athlete 02','Test Athlete 02','female','2000-01-02','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(3,90,'Athlete 03','Test Athlete 03','male','2000-01-03','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(4,91,'Athlete 04','Test Athlete 04','female','2000-01-04','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(5,92,'Athlete 05','Test Athlete 05','male','2000-01-05','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(6,93,'Athlete 06','Test Athlete 06','female','2000-01-06','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(7,94,'Athlete 07','Test Athlete 07','male','2000-01-07','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(8,95,'Athlete 08','Test Athlete 08','female','2000-01-08','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(9,96,'Athlete 09','Test Athlete 09','male','2000-01-09','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(10,97,'Athlete 10','Test Athlete 10','female','2000-01-10','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(11,98,'Athlete 11','Test Athlete 11','male','2000-01-11','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(12,99,'Athlete 12','Test Athlete 12','female','2000-01-12','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(13,100,'Athlete 13','Test Athlete 13','male','2000-01-13','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(14,101,'Athlete 14','Test Athlete 14','female','2000-01-14','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(15,102,'Athlete 15','Test Athlete 15','male','2000-01-15','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(16,103,'Athlete 16','Test Athlete 16','female','2000-01-16','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(17,104,'Athlete 17','Test Athlete 17','male','2000-01-17','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(18,105,'Athlete 18','Test Athlete 18','female','2000-01-18','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(19,106,'Athlete 19','Test Athlete 19','male','2000-01-19','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(20,107,'Athlete 20','Test Athlete 20','female','2000-01-20','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(21,108,'Athlete 21','Test Athlete 21','male','2000-01-21','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(22,109,'Athlete 22','Test Athlete 22','female','2000-01-22','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(23,110,'Athlete 23','Test Athlete 23','male','2000-01-23','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(24,111,'Athlete 24','Test Athlete 24','female','2000-01-24','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(25,112,'Athlete 25','Test Athlete 25','male','2000-01-25','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(26,113,'Athlete 26','Test Athlete 26','female','2000-01-26','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(27,114,'Athlete 27','Test Athlete 27','male','2000-01-27','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(28,115,'Athlete 28','Test Athlete 28','female','2000-01-28','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(29,116,'Athlete 29','Test Athlete 29','male','2000-01-01','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(30,117,'Athlete 30','Test Athlete 30','female','2000-01-02','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(31,118,'Athlete 31','Test Athlete 31','male','2000-01-03','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(32,119,'Athlete 32','Test Athlete 32','female','2000-01-04','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(33,120,'Athlete 33','Test Athlete 33','male','2000-01-05','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(34,121,'Athlete 34','Test Athlete 34','female','2000-01-06','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(35,122,'Athlete 35','Test Athlete 35','male','2000-01-07','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(36,123,'Athlete 36','Test Athlete 36','female','2000-01-08','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(37,124,'Athlete 37','Test Athlete 37','male','2000-01-09','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(38,125,'Athlete 38','Test Athlete 38','female','2000-01-10','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(39,126,'Athlete 39','Test Athlete 39','male','2000-01-11','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31'),(40,127,'Athlete 40','Test Athlete 40','female','2000-01-12','unverified',0,NULL,NULL,'α╕Öα╕äα╕úα╕úα╕▓α╕èα╕¬α╕╡α╕íα╕▓','2026-08-21 12:16:31');
/*!40000 ALTER TABLE `players` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ranking_history`
--

DROP TABLE IF EXISTS `ranking_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ranking_history` (
  `ranking_history_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `game_id` int(10) unsigned NOT NULL,
  `tournament_id` int(10) unsigned NOT NULL,
  `tournament_category_id` int(10) unsigned DEFAULT NULL,
  `player_id` int(10) unsigned DEFAULT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `placement` smallint(5) unsigned DEFAULT NULL,
  `points` int(11) NOT NULL DEFAULT 0,
  `reason` varchar(500) DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`ranking_history_id`),
  KEY `game_id` (`game_id`),
  KEY `tournament_id` (`tournament_id`),
  KEY `tournament_category_id` (`tournament_category_id`),
  KEY `player_game` (`player_id`,`game_id`,`created_at`),
  KEY `team_game` (`team_id`,`game_id`,`created_at`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `ranking_history_category_fk` FOREIGN KEY (`tournament_category_id`) REFERENCES `tournament_categories` (`tournament_category_id`) ON DELETE SET NULL,
  CONSTRAINT `ranking_history_game_fk` FOREIGN KEY (`game_id`) REFERENCES `games` (`game_id`) ON DELETE CASCADE,
  CONSTRAINT `ranking_history_player_fk` FOREIGN KEY (`player_id`) REFERENCES `players` (`player_id`) ON DELETE CASCADE,
  CONSTRAINT `ranking_history_team_fk` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`) ON DELETE CASCADE,
  CONSTRAINT `ranking_history_tournament_fk` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`tournament_id`) ON DELETE CASCADE,
  CONSTRAINT `ranking_history_user_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ranking_history`
--

LOCK TABLES `ranking_history` WRITE;
/*!40000 ALTER TABLE `ranking_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `ranking_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `registration_status_history`
--

DROP TABLE IF EXISTS `registration_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `registration_status_history` (
  `registration_status_history_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tournament_registration_id` int(10) unsigned NOT NULL,
  `old_status` varchar(30) DEFAULT NULL,
  `new_status` varchar(30) NOT NULL,
  `changed_by` int(10) unsigned DEFAULT NULL,
  `change_note` varchar(500) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`registration_status_history_id`),
  KEY `registration_status_history_registration_idx` (`tournament_registration_id`),
  KEY `registration_status_history_user_fk` (`changed_by`),
  CONSTRAINT `registration_status_history_registration_fk` FOREIGN KEY (`tournament_registration_id`) REFERENCES `tournament_registrations` (`tournament_registration_id`) ON DELETE CASCADE,
  CONSTRAINT `registration_status_history_user_fk` FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `registration_status_history`
--

LOCK TABLES `registration_status_history` WRITE;
/*!40000 ALTER TABLE `registration_status_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `registration_status_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `team_invitations`
--

DROP TABLE IF EXISTS `team_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team_invitations` (
  `invitation_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int(11) NOT NULL,
  `invited_player_id` int(11) NOT NULL,
  `invited_by_player_id` int(11) NOT NULL,
  `status` enum('pending','accepted','declined') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `responded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`invitation_id`),
  UNIQUE KEY `unique_team_invitee` (`team_id`,`invited_player_id`),
  KEY `invited_player_status` (`invited_player_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `team_invitations`
--

LOCK TABLES `team_invitations` WRITE;
/*!40000 ALTER TABLE `team_invitations` DISABLE KEYS */;
/*!40000 ALTER TABLE `team_invitations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `team_member_roles`
--

DROP TABLE IF EXISTS `team_member_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team_member_roles` (
  `team_member_role_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `team_member_id` int(10) unsigned NOT NULL,
  `role_code` varchar(30) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`team_member_role_id`),
  UNIQUE KEY `team_member_roles_unique` (`team_member_id`,`role_code`),
  KEY `team_member_roles_member_idx` (`team_member_id`),
  CONSTRAINT `team_member_roles_member_fk` FOREIGN KEY (`team_member_id`) REFERENCES `team_members` (`team_member_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=673 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `team_member_roles`
--

LOCK TABLES `team_member_roles` WRITE;
/*!40000 ALTER TABLE `team_member_roles` DISABLE KEYS */;
INSERT INTO `team_member_roles` VALUES (1,1,'player','2026-08-21 12:16:31'),(2,2,'player','2026-08-21 12:16:31'),(3,3,'player','2026-08-21 12:16:31'),(4,4,'player','2026-08-21 12:16:31'),(5,5,'player','2026-08-21 12:16:31'),(6,6,'player','2026-08-21 12:16:31'),(7,7,'player','2026-08-21 12:16:31'),(8,8,'player','2026-08-21 12:16:31'),(9,9,'player','2026-08-21 12:16:31'),(10,10,'player','2026-08-21 12:16:31'),(11,11,'player','2026-08-21 12:16:31'),(12,12,'player','2026-08-21 12:16:31'),(13,13,'player','2026-08-21 12:16:31'),(14,14,'player','2026-08-21 12:16:31'),(15,15,'player','2026-08-21 12:16:31'),(16,16,'player','2026-08-21 12:16:31'),(17,17,'player','2026-08-21 12:16:31'),(18,18,'player','2026-08-21 12:16:31'),(19,19,'player','2026-08-21 12:16:31'),(20,20,'player','2026-08-21 12:16:31'),(21,21,'player','2026-08-21 12:16:31'),(22,22,'player','2026-08-21 12:16:31'),(23,23,'player','2026-08-21 12:16:31'),(24,24,'player','2026-08-21 12:16:31'),(25,25,'player','2026-08-21 12:16:31'),(26,26,'player','2026-08-21 12:16:31'),(27,27,'player','2026-08-21 12:16:31'),(28,28,'player','2026-08-21 12:16:31'),(29,29,'player','2026-08-21 12:16:31'),(30,30,'player','2026-08-21 12:16:31'),(31,31,'player','2026-08-21 12:16:31'),(32,32,'player','2026-08-21 12:16:31'),(33,33,'player','2026-08-21 12:16:31'),(34,34,'player','2026-08-21 12:16:31'),(35,35,'player','2026-08-21 12:16:31'),(36,36,'player','2026-08-21 12:16:31'),(37,37,'player','2026-08-21 12:16:31'),(38,38,'player','2026-08-21 12:16:31'),(39,39,'player','2026-08-21 12:16:31'),(40,40,'player','2026-08-21 12:16:31');
/*!40000 ALTER TABLE `team_member_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `team_members`
--

DROP TABLE IF EXISTS `team_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team_members` (
  `team_member_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int(10) unsigned NOT NULL,
  `player_id` int(10) unsigned NOT NULL,
  `in_game_role` varchar(50) DEFAULT NULL,
  `member_roles` set('manager','coach','player','substitute') NOT NULL DEFAULT 'player',
  `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
  `left_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`team_member_id`),
  UNIQUE KEY `uq_active_member` (`team_id`,`player_id`),
  KEY `player_id` (`player_id`),
  CONSTRAINT `team_members_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`) ON DELETE CASCADE,
  CONSTRAINT `team_members_ibfk_2` FOREIGN KEY (`player_id`) REFERENCES `players` (`player_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `team_members`
--

LOCK TABLES `team_members` WRITE;
/*!40000 ALTER TABLE `team_members` DISABLE KEYS */;
INSERT INTO `team_members` VALUES (1,1,1,'player','player','2026-08-21 12:16:31',NULL,1),(2,1,2,'player','player','2026-08-21 12:16:31',NULL,1),(3,1,3,'player','player','2026-08-21 12:16:31',NULL,1),(4,1,4,'player','player','2026-08-21 12:16:31',NULL,1),(5,1,5,'player','player','2026-08-21 12:16:31',NULL,1),(6,2,6,'player','player','2026-08-21 12:16:31',NULL,1),(7,2,7,'player','player','2026-08-21 12:16:31',NULL,1),(8,2,8,'player','player','2026-08-21 12:16:31',NULL,1),(9,2,9,'player','player','2026-08-21 12:16:31',NULL,1),(10,2,10,'player','player','2026-08-21 12:16:31',NULL,1),(11,3,11,'player','player','2026-08-21 12:16:31',NULL,1),(12,3,12,'player','player','2026-08-21 12:16:31',NULL,1),(13,3,13,'player','player','2026-08-21 12:16:31',NULL,1),(14,3,14,'player','player','2026-08-21 12:16:31',NULL,1),(15,3,15,'player','player','2026-08-21 12:16:31',NULL,1),(16,4,16,'player','player','2026-08-21 12:16:31',NULL,1),(17,4,17,'player','player','2026-08-21 12:16:31',NULL,1),(18,4,18,'player','player','2026-08-21 12:16:31',NULL,1),(19,4,19,'player','player','2026-08-21 12:16:31',NULL,1),(20,4,20,'player','player','2026-08-21 12:16:31',NULL,1),(21,5,21,'player','player','2026-08-21 12:16:31',NULL,1),(22,5,22,'player','player','2026-08-21 12:16:31',NULL,1),(23,5,23,'player','player','2026-08-21 12:16:31',NULL,1),(24,5,24,'player','player','2026-08-21 12:16:31',NULL,1),(25,5,25,'player','player','2026-08-21 12:16:31',NULL,1),(26,6,26,'player','player','2026-08-21 12:16:31',NULL,1),(27,6,27,'player','player','2026-08-21 12:16:31',NULL,1),(28,6,28,'player','player','2026-08-21 12:16:31',NULL,1),(29,6,29,'player','player','2026-08-21 12:16:31',NULL,1),(30,6,30,'player','player','2026-08-21 12:16:31',NULL,1),(31,7,31,'player','player','2026-08-21 12:16:31',NULL,1),(32,7,32,'player','player','2026-08-21 12:16:31',NULL,1),(33,7,33,'player','player','2026-08-21 12:16:31',NULL,1),(34,7,34,'player','player','2026-08-21 12:16:31',NULL,1),(35,7,35,'player','player','2026-08-21 12:16:31',NULL,1),(36,8,36,'player','player','2026-08-21 12:16:31',NULL,1),(37,8,37,'player','player','2026-08-21 12:16:31',NULL,1),(38,8,38,'player','player','2026-08-21 12:16:31',NULL,1),(39,8,39,'player','player','2026-08-21 12:16:31',NULL,1),(40,8,40,'player','player','2026-08-21 12:16:31',NULL,1);
/*!40000 ALTER TABLE `team_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `team_rankings`
--

DROP TABLE IF EXISTS `team_rankings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team_rankings` (
  `team_ranking_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `game_id` int(10) unsigned NOT NULL,
  `team_id` int(10) unsigned NOT NULL,
  `category` varchar(50) DEFAULT 'open',
  `points` int(11) NOT NULL DEFAULT 0,
  `matches_played` smallint(5) unsigned NOT NULL DEFAULT 0,
  `wins` smallint(5) unsigned NOT NULL DEFAULT 0,
  `losses` smallint(5) unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`team_ranking_id`),
  UNIQUE KEY `uq_team_ranking` (`game_id`,`team_id`,`category`),
  KEY `team_id` (`team_id`),
  CONSTRAINT `team_rankings_ibfk_1` FOREIGN KEY (`game_id`) REFERENCES `games` (`game_id`) ON DELETE CASCADE,
  CONSTRAINT `team_rankings_ibfk_2` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `team_rankings`
--

LOCK TABLES `team_rankings` WRITE;
/*!40000 ALTER TABLE `team_rankings` DISABLE KEYS */;
INSERT INTO `team_rankings` VALUES (1,2,3,'male',3,2,1,1,'2026-08-21 20:35:38'),(2,2,2,'male',0,1,0,1,'2026-08-21 20:34:55'),(3,2,4,'male',6,2,2,0,'2026-08-21 20:35:38'),(4,2,1,'male',0,1,0,1,'2026-08-21 20:35:06'),(5,2,6,'female',3,1,1,0,'2026-08-21 20:35:21'),(6,2,5,'female',0,1,0,1,'2026-08-21 20:35:21');
/*!40000 ALTER TABLE `team_rankings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teams`
--

DROP TABLE IF EXISTS `teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teams` (
  `team_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `game_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `tag` varchar(10) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `captain_player_id` int(10) unsigned DEFAULT NULL,
  `is_solo_wrapper` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `team_category` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive','suspended','disbanded') NOT NULL DEFAULT 'active',
  `status_reason` varchar(500) DEFAULT NULL,
  `status_changed_at` datetime DEFAULT NULL,
  `status_changed_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`team_id`),
  KEY `game_id` (`game_id`),
  KEY `captain_player_id` (`captain_player_id`),
  KEY `teams_status_idx` (`status`),
  KEY `teams_status_changed_by_idx` (`status_changed_by`),
  CONSTRAINT `teams_ibfk_2` FOREIGN KEY (`captain_player_id`) REFERENCES `players` (`player_id`) ON DELETE SET NULL,
  CONSTRAINT `teams_status_changed_by_fk` FOREIGN KEY (`status_changed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teams`
--

LOCK TABLES `teams` WRITE;
/*!40000 ALTER TABLE `teams` DISABLE KEYS */;
INSERT INTO `teams` VALUES (1,2,'Team 01','T01',NULL,1,0,'2026-08-21 12:16:31','open','active',NULL,NULL,NULL),(2,2,'Team 02','T02',NULL,6,0,'2026-08-21 12:16:31','open','active',NULL,NULL,NULL),(3,2,'Team 03','T03',NULL,11,0,'2026-08-21 12:16:31','open','active',NULL,NULL,NULL),(4,2,'Team 04','T04',NULL,16,0,'2026-08-21 12:16:31','open','active',NULL,NULL,NULL),(5,2,'Team 05','T05',NULL,21,0,'2026-08-21 12:16:31','open','active',NULL,NULL,NULL),(6,2,'Team 06','T06',NULL,26,0,'2026-08-21 12:16:31','open','active',NULL,NULL,NULL),(7,2,'Team 07','T07',NULL,31,0,'2026-08-21 12:16:31','open','active',NULL,NULL,NULL),(8,2,'Team 08','T08',NULL,36,0,'2026-08-21 12:16:31','open','active',NULL,NULL,NULL);
/*!40000 ALTER TABLE `teams` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tournament_categories`
--

DROP TABLE IF EXISTS `tournament_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tournament_categories` (
  `tournament_category_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tournament_id` int(10) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) NOT NULL,
  `eligibility_type` enum('open','male','female','mixed','age_limited','custom') NOT NULL DEFAULT 'open',
  `minimum_age` tinyint(3) unsigned DEFAULT NULL,
  `maximum_age` tinyint(3) unsigned DEFAULT NULL,
  `eligibility_rules` text DEFAULT NULL,
  `competition_format` enum('group_only','single_elimination','double_elimination','group_then_single','group_then_double','multi_participant_points') NOT NULL DEFAULT 'single_elimination',
  `required_starters` tinyint(3) unsigned DEFAULT NULL,
  `minimum_roster_size` tinyint(3) unsigned DEFAULT NULL,
  `maximum_roster_size` tinyint(3) unsigned DEFAULT NULL,
  `group_count` smallint(5) unsigned DEFAULT NULL,
  `teams_per_group` smallint(5) unsigned DEFAULT NULL,
  `teams_advance_per_group` smallint(5) unsigned DEFAULT NULL,
  `best_of` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `ranking_config` text DEFAULT NULL,
  `roster_lock_at` datetime DEFAULT NULL,
  `checkin_open_at` datetime DEFAULT NULL,
  `checkin_deadline` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `category_code` varchar(30) DEFAULT NULL,
  `label` varchar(100) DEFAULT NULL,
  `max_participants` int(10) unsigned DEFAULT NULL,
  `format` varchar(30) DEFAULT NULL,
  `group_size` int(10) unsigned DEFAULT NULL,
  `starters_count` int(10) unsigned DEFAULT NULL,
  `substitutes_count` int(10) unsigned DEFAULT NULL,
  `checkin_required_roles` varchar(255) DEFAULT NULL,
  `seed_method` varchar(30) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`tournament_category_id`),
  UNIQUE KEY `uq_tournament_category_code` (`tournament_id`,`code`),
  KEY `tournament_id` (`tournament_id`),
  CONSTRAINT `tournament_categories_tournament_fk` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`tournament_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=83 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tournament_categories`
--

LOCK TABLES `tournament_categories` WRITE;
/*!40000 ALTER TABLE `tournament_categories` DISABLE KEYS */;
INSERT INTO `tournament_categories` VALUES (78,122,'','male','open',NULL,NULL,NULL,'single_elimination',NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,NULL,NULL,NULL,'2026-08-25 15:12:39','male','α╕èα╕▓α╕ó',8,'single_elimination',NULL,5,1,'player','ranking',1),(79,122,'','female','open',NULL,NULL,NULL,'single_elimination',NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,NULL,NULL,NULL,'2026-08-25 15:12:39','female','α╕½α╕ìα╕┤α╕ç',8,'single_elimination',NULL,5,1,'player','ranking',1),(80,123,'','male','open',NULL,NULL,NULL,'single_elimination',NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,NULL,NULL,NULL,'2026-08-25 15:13:14','male','α╕èα╕▓α╕ó',16,'single_elimination',NULL,NULL,NULL,NULL,NULL,0),(81,123,'','female','open',NULL,NULL,NULL,'single_elimination',NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,NULL,NULL,NULL,'2026-08-25 15:13:14','female','α╕½α╕ìα╕┤α╕ç',16,'single_elimination',NULL,NULL,NULL,NULL,NULL,0),(82,123,'','open','open',NULL,NULL,NULL,'single_elimination',NULL,NULL,NULL,NULL,NULL,NULL,1,NULL,NULL,NULL,NULL,'2026-08-25 15:13:14','open','Open',16,'single_elimination',NULL,5,1,'player','ranking',1);
/*!40000 ALTER TABLE `tournament_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tournament_days`
--

DROP TABLE IF EXISTS `tournament_days`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tournament_days` (
  `tournament_day_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tournament_id` int(10) unsigned NOT NULL,
  `day_number` int(10) unsigned NOT NULL,
  `event_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `venue_name` varchar(255) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`tournament_day_id`),
  UNIQUE KEY `tournament_day_unique` (`tournament_id`,`day_number`),
  CONSTRAINT `tournament_days_tournament_fk` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`tournament_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tournament_days`
--

LOCK TABLES `tournament_days` WRITE;
/*!40000 ALTER TABLE `tournament_days` DISABLE KEYS */;
/*!40000 ALTER TABLE `tournament_days` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tournament_groups`
--

DROP TABLE IF EXISTS `tournament_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tournament_groups` (
  `tournament_group_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tournament_id` int(10) unsigned NOT NULL,
  `tournament_category_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(20) NOT NULL,
  PRIMARY KEY (`tournament_group_id`),
  KEY `tournament_id` (`tournament_id`),
  KEY `tournament_category_id` (`tournament_category_id`),
  CONSTRAINT `tournament_groups_category_fk` FOREIGN KEY (`tournament_category_id`) REFERENCES `tournament_categories` (`tournament_category_id`) ON DELETE CASCADE,
  CONSTRAINT `tournament_groups_ibfk_1` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`tournament_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tournament_groups`
--

LOCK TABLES `tournament_groups` WRITE;
/*!40000 ALTER TABLE `tournament_groups` DISABLE KEYS */;
/*!40000 ALTER TABLE `tournament_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tournament_registration_members`
--

DROP TABLE IF EXISTS `tournament_registration_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tournament_registration_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tournament_registration_id` int(10) unsigned NOT NULL,
  `player_id` int(10) unsigned NOT NULL,
  `member_roles` set('manager','coach','player','substitute') NOT NULL DEFAULT 'player',
  `is_starter` tinyint(1) NOT NULL DEFAULT 1,
  `is_required_for_checkin` tinyint(1) NOT NULL DEFAULT 1,
  `checkin_status` enum('not_checked_in','checked_in','waived') NOT NULL DEFAULT 'not_checked_in',
  `checkin_at` datetime DEFAULT NULL,
  `roster_status` enum('active','removed','replaced') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `checkin_waived_reason` varchar(500) DEFAULT NULL,
  `checkin_waived_by` int(10) unsigned DEFAULT NULL,
  `checkin_waived_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_registration_player` (`tournament_registration_id`,`player_id`),
  KEY `player_id` (`player_id`),
  KEY `checkin_pool` (`tournament_registration_id`,`is_starter`,`checkin_status`,`roster_status`),
  CONSTRAINT `registration_members_player_fk` FOREIGN KEY (`player_id`) REFERENCES `players` (`player_id`) ON DELETE CASCADE,
  CONSTRAINT `registration_members_registration_fk` FOREIGN KEY (`tournament_registration_id`) REFERENCES `tournament_registrations` (`tournament_registration_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=112 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tournament_registration_members`
--

LOCK TABLES `tournament_registration_members` WRITE;
/*!40000 ALTER TABLE `tournament_registration_members` DISABLE KEYS */;
/*!40000 ALTER TABLE `tournament_registration_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tournament_registrations`
--

DROP TABLE IF EXISTS `tournament_registrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tournament_registrations` (
  `tournament_registration_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tournament_id` int(10) unsigned NOT NULL,
  `tournament_category_id` int(10) unsigned DEFAULT NULL,
  `team_id` int(11) DEFAULT NULL,
  `player_id` int(10) unsigned DEFAULT NULL,
  `category` varchar(50) DEFAULT 'open',
  `seed` smallint(5) unsigned DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `participation_status` enum('registered','checkin_open','checkin_complete','checkin_incomplete','pending_admin_review','qualified_for_draw','withdrawn','disqualified') NOT NULL DEFAULT 'registered',
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` varchar(500) DEFAULT NULL,
  `roster_locked_at` datetime DEFAULT NULL,
  `qr_code_token` varchar(64) DEFAULT NULL,
  `checkin_status` enum('not_checked_in','checked_in') NOT NULL DEFAULT 'not_checked_in',
  `checkin_at` datetime DEFAULT NULL,
  `registered_at` datetime NOT NULL DEFAULT current_timestamp(),
  `seed_no` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`tournament_registration_id`),
  UNIQUE KEY `uq_team_per_category` (`tournament_category_id`,`team_id`),
  KEY `team_id` (`team_id`),
  KEY `idx_player_id` (`player_id`),
  KEY `tournament_category_id` (`tournament_category_id`),
  KEY `reviewed_by` (`reviewed_by`),
  KEY `draw_pool` (`tournament_category_id`,`status`,`participation_status`),
  KEY `tournament_registrations_ibfk_1` (`tournament_id`),
  CONSTRAINT `tournament_registrations_category_fk` FOREIGN KEY (`tournament_category_id`) REFERENCES `tournament_categories` (`tournament_category_id`) ON DELETE CASCADE,
  CONSTRAINT `tournament_registrations_ibfk_1` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`tournament_id`) ON DELETE CASCADE,
  CONSTRAINT `tournament_registrations_reviewed_by_fk` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `tr_player_fk` FOREIGN KEY (`player_id`) REFERENCES `players` (`player_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=452 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tournament_registrations`
--

LOCK TABLES `tournament_registrations` WRITE;
/*!40000 ALTER TABLE `tournament_registrations` DISABLE KEYS */;
/*!40000 ALTER TABLE `tournament_registrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tournaments`
--

DROP TABLE IF EXISTS `tournaments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tournaments` (
  `tournament_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `game_id` int(10) unsigned NOT NULL,
  `category` enum('male','female','mixed','open') NOT NULL DEFAULT 'open',
  `name` varchar(150) NOT NULL,
  `format` enum('single_elimination','double_elimination') NOT NULL,
  `gender_category` varchar(20) NOT NULL DEFAULT 'general',
  `best_of` tinyint(4) NOT NULL DEFAULT 1,
  `category_gender` enum('all','male','female') NOT NULL DEFAULT 'all',
  `status` enum('draft','registration_open','registration_closed','ongoing','completed','cancelled') NOT NULL DEFAULT 'draft',
  `venue_address` varchar(255) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `venue_lat_lng` varchar(50) DEFAULT NULL,
  `max_teams` smallint(5) unsigned NOT NULL,
  `prize_pool` varchar(255) DEFAULT NULL,
  `rules` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `group_count` tinyint(3) unsigned DEFAULT NULL,
  `teams_advance_per_group` tinyint(3) unsigned DEFAULT NULL,
  `registration_start` datetime DEFAULT NULL,
  `registration_end` datetime DEFAULT NULL,
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `checkin_open_at` datetime DEFAULT NULL,
  `checkin_close_at` datetime DEFAULT NULL,
  `roster_lock_at` datetime DEFAULT NULL,
  PRIMARY KEY (`tournament_id`),
  KEY `game_id` (`game_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `tournaments_ibfk_1` FOREIGN KEY (`game_id`) REFERENCES `games` (`game_id`) ON DELETE CASCADE,
  CONSTRAINT `tournaments_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=124 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tournaments`
--

LOCK TABLES `tournaments` WRITE;
/*!40000 ALTER TABLE `tournaments` DISABLE KEYS */;
INSERT INTO `tournaments` VALUES (122,2,'open','ChamTest6 α╕è α╕ì','single_elimination','general',3,'all','registration_open','',NULL,'',8,'20000','ARENA OF VALOR (RoV)\r\n1. α╕úα╕╣α╕¢α╣üα╕Üα╕Üα╕üα╕▓α╕úα╣üα╕éα╣êα╕çα╕éα╕▒α╕Ö\r\n1.1. α╕úα╕¡α╕Üα╣üα╕Üα╣êα╕çα╕üα╕Ñα╕╕α╣êα╕í (α╣üα╕Üα╣êα╕çα╕üα╕Ñα╕╕α╣êα╕í α╣üα╕éα╣êα╕çα╣üα╕Üα╕Ü BO2 α╕èα╕Öα╕░α╣äα╕öα╣ë 2, α╣Çα╕¬α╕íα╕¡ 1 α╕äα╕░α╣üα╕Öα╕Ö α╕òα╕▒α╕öα╕¬α╕┤α╕Öα╕êα╕▓α╕ü Kill α╣üα╕Ñα╕░ Time Rating α╕¡α╕▒α╕Öα╕öα╕▒α╕Ü 1-2 α╣Çα╕éα╣ëα╕▓α╕úα╕¡α╕Ü Playoff)\r\n1.2. α╕úα╕¡α╕Ü Playoff (Single Elimination BO3)\r\n2. α╕üα╕òα╕┤α╕üα╕▓α╕üα╕▓α╕úα╣üα╕éα╣êα╕çα╕éα╕▒α╕Ö\r\n2.1. α╣éα╕½α╕íα╕ö 5V5 α╣âα╕èα╣ëα╕úα╕░α╕Üα╕Ü Global Ban/Pick\r\n2.2. α╕üα╕▓α╕úα╣Çα╕Ñα╕╖α╕¡α╕üα╕¥α╕▒α╣êα╕ç: α╣Çα╕üα╕íα╕ùα╕╡α╣ê 1 α╕ùα╕¡α╕óα╣Çα╕½α╕úα╕╡α╕óα╕ì, α╕òα╕▒α╣ëα╕çα╣üα╕òα╣êα╣Çα╕üα╕íα╕ùα╕╡α╣ê 2 α╣âα╕½α╣ëα╕ùα╕╡α╕íα╣üα╕₧α╣ëα╣Çα╕Ñα╕╖α╕¡α╕üα╕¥α╕▒α╣êα╕ç\r\n2.3. α╕½α╣ëα╕▓α╕íα╣âα╕èα╣ë Hero α╕ùα╕╡α╣êα╕¡α╕▒α╕₧α╣Çα╕öα╕ùα╕óα╕▒α╕çα╣äα╕íα╣êα╕ûα╕╢α╕ç 14 α╕ºα╕▒α╕Ö / α╕½α╣ëα╕▓α╕íα╣âα╕èα╣ëα╕¬α╕üα╕┤α╕Öα╕ùα╕╡α╣êα╕íα╕╡α╕¢α╕▒α╕ìα╕½α╕▓α╕Üα╕▒α╣èα╕ü\r\n2.4. α╕½α╣ëα╕▓α╕íα╕½α╕óα╕╕α╕öα╕₧α╕▒α╕üα╣Çα╕üα╕íα╕úα╕░α╕½α╕ºα╣êα╕▓α╕ç Fight α╕ùα╕╕α╕üα╕üα╕úα╕ôα╕╡ α╕½α╕▓α╕üα╕¥α╣êα╕▓α╕¥α╕╖α╕Öα╣Çα╕òα╕╖α╕¡α╕Öα╕½α╕úα╕╖α╕¡α╕¢α╕úα╕▒α╕Üα╣üα╕₧α╣ëα╣âα╕Öα╣Çα╕üα╕íα╕Öα╕▒α╣ëα╕Ö','',NULL,NULL,'2026-08-25 15:15:00','2026-08-25 15:30:00','2026-08-25 15:55:00','2026-08-25 17:15:00',1,'2026-08-25 15:12:39','2026-08-25 15:40:00','2026-08-25 15:50:00','2026-08-25 15:35:00'),(123,2,'open','ChamTest open','single_elimination','general',3,'all','registration_open','',NULL,'',16,'20000','ARENA OF VALOR (RoV)\r\n1. α╕úα╕╣α╕¢α╣üα╕Üα╕Üα╕üα╕▓α╕úα╣üα╕éα╣êα╕çα╕éα╕▒α╕Ö\r\n1.1. α╕úα╕¡α╕Üα╣üα╕Üα╣êα╕çα╕üα╕Ñα╕╕α╣êα╕í (α╣üα╕Üα╣êα╕çα╕üα╕Ñα╕╕α╣êα╕í α╣üα╕éα╣êα╕çα╣üα╕Üα╕Ü BO2 α╕èα╕Öα╕░α╣äα╕öα╣ë 2, α╣Çα╕¬α╕íα╕¡ 1 α╕äα╕░α╣üα╕Öα╕Ö α╕òα╕▒α╕öα╕¬α╕┤α╕Öα╕êα╕▓α╕ü Kill α╣üα╕Ñα╕░ Time Rating α╕¡α╕▒α╕Öα╕öα╕▒α╕Ü 1-2 α╣Çα╕éα╣ëα╕▓α╕úα╕¡α╕Ü Playoff)\r\n1.2. α╕úα╕¡α╕Ü Playoff (Single Elimination BO3)\r\n2. α╕üα╕òα╕┤α╕üα╕▓α╕üα╕▓α╕úα╣üα╕éα╣êα╕çα╕éα╕▒α╕Ö\r\n2.1. α╣éα╕½α╕íα╕ö 5V5 α╣âα╕èα╣ëα╕úα╕░α╕Üα╕Ü Global Ban/Pick\r\n2.2. α╕üα╕▓α╕úα╣Çα╕Ñα╕╖α╕¡α╕üα╕¥α╕▒α╣êα╕ç: α╣Çα╕üα╕íα╕ùα╕╡α╣ê 1 α╕ùα╕¡α╕óα╣Çα╕½α╕úα╕╡α╕óα╕ì, α╕òα╕▒α╣ëα╕çα╣üα╕òα╣êα╣Çα╕üα╕íα╕ùα╕╡α╣ê 2 α╣âα╕½α╣ëα╕ùα╕╡α╕íα╣üα╕₧α╣ëα╣Çα╕Ñα╕╖α╕¡α╕üα╕¥α╕▒α╣êα╕ç\r\n2.3. α╕½α╣ëα╕▓α╕íα╣âα╕èα╣ë Hero α╕ùα╕╡α╣êα╕¡α╕▒α╕₧α╣Çα╕öα╕ùα╕óα╕▒α╕çα╣äα╕íα╣êα╕ûα╕╢α╕ç 14 α╕ºα╕▒α╕Ö / α╕½α╣ëα╕▓α╕íα╣âα╕èα╣ëα╕¬α╕üα╕┤α╕Öα╕ùα╕╡α╣êα╕íα╕╡α╕¢α╕▒α╕ìα╕½α╕▓α╕Üα╕▒α╣èα╕ü\r\n2.4. α╕½α╣ëα╕▓α╕íα╕½α╕óα╕╕α╕öα╕₧α╕▒α╕üα╣Çα╕üα╕íα╕úα╕░α╕½α╕ºα╣êα╕▓α╕ç Fight α╕ùα╕╕α╕üα╕üα╕úα╕ôα╕╡ α╕½α╕▓α╕üα╕¥α╣êα╕▓α╕¥α╕╖α╕Öα╣Çα╕òα╕╖α╕¡α╕Öα╕½α╕úα╕╖α╕¡α╕¢α╕úα╕▒α╕Üα╣üα╕₧α╣ëα╣âα╕Öα╣Çα╕üα╕íα╕Öα╕▒α╣ëα╕Ö','',NULL,NULL,'2026-08-25 15:15:00','2026-08-25 15:30:00','2026-08-25 15:55:00','2026-08-25 17:15:00',1,'2026-08-25 15:13:14','2026-08-25 15:40:00','2026-08-25 15:50:00','2026-08-25 15:35:00');
/*!40000 ALTER TABLE `tournaments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(120) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','athlete','guest') NOT NULL DEFAULT 'guest',
  `security_question` varchar(255) DEFAULT NULL,
  `security_answer_hash` varchar(255) DEFAULT NULL,
  `status` enum('active','suspended','disabled') NOT NULL DEFAULT 'active',
  `suspended_at` datetime DEFAULT NULL,
  `suspended_by` int(10) unsigned DEFAULT NULL,
  `suspension_reason` varchar(500) DEFAULT NULL,
  `reactivated_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_athlete` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `suspended_by` (`suspended_by`),
  CONSTRAINT `users_suspended_by_fk` FOREIGN KEY (`suspended_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=128 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin','adminkorat@gmail.com','$2y$10$ov/mKqLedrCY8oUS9juA7.EswmpfETH2/vwz/HWUeT3aOy6pyO5Xu','admin',NULL,NULL,'active',NULL,NULL,NULL,NULL,'2026-08-25 13:08:38','2026-07-11 22:47:50','2026-08-25 13:08:38',0),(88,'athlete01','athlete01@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,'2026-08-21 14:05:26','2026-08-21 15:22:44','2026-08-21 12:16:31','2026-08-21 15:22:44',0),(89,'athlete02','athlete02@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(90,'athlete03','athlete03@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(91,'athlete04','athlete04@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(92,'athlete05','athlete05@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(93,'athlete06','athlete06@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,'2026-08-21 15:09:34','2026-08-21 12:16:31','2026-08-21 15:09:34',0),(94,'athlete07','athlete07@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(95,'athlete08','athlete08@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(96,'athlete09','athlete09@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(97,'athlete10','athlete10@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(98,'athlete11','athlete11@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,'2026-08-21 15:10:04','2026-08-21 12:16:31','2026-08-21 15:10:04',0),(99,'athlete12','athlete12@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(100,'athlete13','athlete13@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(101,'athlete14','athlete14@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(102,'athlete15','athlete15@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(103,'athlete16','athlete16@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,'2026-08-21 15:10:30','2026-08-21 12:16:31','2026-08-21 15:10:30',0),(104,'athlete17','athlete17@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(105,'athlete18','athlete18@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(106,'athlete19','athlete19@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(107,'athlete20','athlete20@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(108,'athlete21','athlete21@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,'2026-08-21 15:10:47','2026-08-21 12:16:31','2026-08-21 15:10:47',0),(109,'athlete22','athlete22@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(110,'athlete23','athlete23@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(111,'athlete24','athlete24@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(112,'athlete25','athlete25@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(113,'athlete26','athlete26@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,'2026-08-21 15:11:04','2026-08-21 12:16:31','2026-08-21 15:11:04',0),(114,'athlete27','athlete27@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(115,'athlete28','athlete28@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(116,'athlete29','athlete29@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(117,'athlete30','athlete30@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(118,'athlete31','athlete31@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(119,'athlete32','athlete32@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(120,'athlete33','athlete33@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(121,'athlete34','athlete34@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(122,'athlete35','athlete35@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(123,'athlete36','athlete36@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(124,'athlete37','athlete37@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(125,'athlete38','athlete38@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(126,'athlete39','athlete39@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0),(127,'athlete40','athlete40@test.local','$2y$12$9EcQXj5aDrAux24V17eUzOgdJ1HMkmv3J4HDJycqEA0UMZxXvGgie','athlete','α╣Çα╕üα╕íα╕ùα╕╡α╣êα╕äα╕╕α╕ôα╣Çα╕Ñα╣êα╕Öα╣Çα╕¢α╣çα╕Öα╣Çα╕üα╕íα╣üα╕úα╕üα╕äα╕╖α╕¡α╣Çα╕üα╕íα╕¡α╕░α╣äα╕ú','$2y$12$7jjWcUgslw7AdzXVG1O7Fu451dMY17JUXuww2v.8oGPKlgUF1a1FO','active',NULL,NULL,NULL,NULL,NULL,'2026-08-21 12:16:31','2026-08-21 12:16:31',0);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'esport_korattest'
--

--
-- Dumping routines for database 'esport_korattest'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-25 15:58:03
