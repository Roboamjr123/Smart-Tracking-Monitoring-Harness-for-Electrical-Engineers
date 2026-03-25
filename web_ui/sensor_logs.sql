-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 21, 2026 at 11:06 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `iot_logs`
--

-- --------------------------------------------------------

--
-- Table structure for table `sensor_logs`
--

CREATE TABLE `sensor_logs` (
  `id` int(11) NOT NULL,
  `temperature` float NOT NULL,
  `humidity` float NOT NULL,
  `crash` int(11) NOT NULL,
  `latitude` double NOT NULL,
  `longitude` double NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sensor_logs`
--

INSERT INTO `sensor_logs` (`id`, `temperature`, `humidity`, `crash`, `latitude`, `longitude`, `created_at`) VALUES
(1, 28.7, 81, 0, 10.297011, 123.896879, '2026-03-21 10:04:51'),
(2, 28.6, 81, 0, 10.297011, 123.896879, '2026-03-21 10:04:55'),
(3, 28.6, 81, 0, 10.297011, 123.896879, '2026-03-21 10:04:58'),
(4, 28.6, 81, 0, 10.297011, 123.896879, '2026-03-21 10:05:01'),
(5, 28.6, 81, 0, 10.297011, 123.896879, '2026-03-21 10:05:05');

--
-- Indexes for dumped tables
--
-- Indexes for table `sensor_logs`
--
ALTER TABLE `sensor_logs`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `sensor_logs`
--
ALTER TABLE `sensor_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

-- --------------------------------------------------------
--
-- Table structure for table `alert_state`
--

CREATE TABLE `alert_state` (
  `id` tinyint(3) unsigned NOT NULL,
  `is_crash_active` tinyint(1) NOT NULL DEFAULT 0,
  `crash_streak` int(11) NOT NULL DEFAULT 0,
  `safe_streak` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Seed data for alert_state
--
INSERT INTO `alert_state` (`id`, `is_crash_active`, `crash_streak`, `safe_streak`)
VALUES (1, 0, 0, 0);

--
-- Indexes for table `alert_state`
--
ALTER TABLE `alert_state`
  ADD PRIMARY KEY (`id`);

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
