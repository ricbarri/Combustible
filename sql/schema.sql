-- ============================================================
-- SISTEMA DE GESTIÓN DE COMBUSTIBLE
-- Schema MySQL
-- ============================================================

CREATE DATABASE IF NOT EXISTS fuel_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE fuel_management;

-- ------------------------------------------------------------
-- SUCURSALES
-- ------------------------------------------------------------
CREATE TABLE branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    address VARCHAR(255),
    phone VARCHAR(30),
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- USUARIOS
-- ------------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    branch_id INT NOT NULL,
    profile ENUM('solicitante','aprobador','cargador') NOT NULL,
    position VARCHAR(100) COMMENT 'Cargo en la empresa',
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id)
);

-- ------------------------------------------------------------
-- MÁQUINAS / ACTIVOS
-- ------------------------------------------------------------
CREATE TABLE machines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    code VARCHAR(60) NOT NULL UNIQUE,
    branch_id INT NOT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id)
);

-- ------------------------------------------------------------
-- ESTANQUES (uno por sucursal)
-- ------------------------------------------------------------
CREATE TABLE tanks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL UNIQUE,
    capacity DECIMAL(10,2) DEFAULT 1000.00,
    current_liters DECIMAL(10,2) DEFAULT 0.00,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id)
);

-- ------------------------------------------------------------
-- REGISTRO DE CARGAS AL ESTANQUE
-- ------------------------------------------------------------
CREATE TABLE tank_loads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tank_id INT NOT NULL,
    branch_id INT NOT NULL,
    user_id INT NOT NULL COMMENT 'Cargador que realiza la carga',
    liters_added DECIMAL(10,2) NOT NULL,
    liters_before DECIMAL(10,2) NOT NULL,
    liters_after DECIMAL(10,2) NOT NULL,
    notes TEXT,
    loaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tank_id) REFERENCES tanks(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ------------------------------------------------------------
-- AJUSTES DE ESTANQUE
-- ------------------------------------------------------------
CREATE TABLE tank_adjustments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tank_id INT NOT NULL,
    branch_id INT NOT NULL,
    user_id INT NOT NULL COMMENT 'Cargador que realiza el ajuste',
    liters_before DECIMAL(10,2) NOT NULL,
    liters_after DECIMAL(10,2) NOT NULL,
    reason TEXT NOT NULL,
    adjusted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tank_id) REFERENCES tanks(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ------------------------------------------------------------
-- FLUJO DE APROBACIÓN POR SUCURSAL
-- ------------------------------------------------------------
CREATE TABLE approval_flows (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    name VARCHAR(150) NOT NULL DEFAULT 'Flujo Principal',
    active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id)
);

CREATE TABLE approval_flow_steps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    flow_id INT NOT NULL,
    user_id INT NOT NULL COMMENT 'Aprobador en este paso',
    step_order INT NOT NULL,
    FOREIGN KEY (flow_id) REFERENCES approval_flows(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY uq_flow_step (flow_id, step_order)
);

-- ------------------------------------------------------------
-- SOLICITUDES DE COMBUSTIBLE
-- ------------------------------------------------------------
CREATE TABLE fuel_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_number VARCHAR(30) NOT NULL UNIQUE,
    requester_id INT NOT NULL,
    branch_id INT NOT NULL COMMENT 'Sucursal de entrega',
    machine_id INT NOT NULL,
    fuel_type ENUM('Petróleo Diesel') NOT NULL DEFAULT 'Petróleo Diesel',
    request_type ENUM('llamada de servicio','activo fijo','excepción') NOT NULL,
    liters_requested DECIMAL(10,2) NOT NULL,
    liters_delivered DECIMAL(10,2) DEFAULT NULL,
    status ENUM('pendiente','en_aprobacion','aprobado','rechazado','entregado') DEFAULT 'pendiente',
    notes TEXT,
    requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME DEFAULT NULL,
    delivered_by INT DEFAULT NULL COMMENT 'Cargador que entregó',
    FOREIGN KEY (requester_id) REFERENCES users(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (machine_id) REFERENCES machines(id),
    FOREIGN KEY (delivered_by) REFERENCES users(id)
);

-- ------------------------------------------------------------
-- APROBACIONES DE SOLICITUD (registro por paso)
-- ------------------------------------------------------------
CREATE TABLE request_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    flow_id INT NOT NULL,
    step_id INT NOT NULL,
    approver_id INT NOT NULL,
    step_order INT NOT NULL,
    status ENUM('pendiente','aprobado','rechazado') DEFAULT 'pendiente',
    comments TEXT,
    acted_at DATETIME DEFAULT NULL,
    notified_at DATETIME DEFAULT NULL,
    FOREIGN KEY (request_id) REFERENCES fuel_requests(id),
    FOREIGN KEY (flow_id) REFERENCES approval_flows(id),
    FOREIGN KEY (step_id) REFERENCES approval_flow_steps(id),
    FOREIGN KEY (approver_id) REFERENCES users(id)
);

-- ------------------------------------------------------------
-- DATOS INICIALES
-- ------------------------------------------------------------

-- Sucursal demo
INSERT INTO branches (name, address, phone) VALUES
('Casa Matriz', 'Av. Principal 1000, Santiago', '+56 2 2000 0001'),
('Sucursal Norte', 'Calle Norte 200, Antofagasta', '+56 55 2000 001'),
('Sucursal Sur', 'Ruta 5 Sur Km 500, Concepción', '+56 41 2000 001');

-- Estanques (uno por sucursal)
INSERT INTO tanks (branch_id, capacity, current_liters) VALUES (1, 1000, 500), (2, 1000, 300), (3, 1000, 750);

-- Usuarios demo (password: Admin1234!)
-- Hash generado con password_hash('Admin1234!', PASSWORD_DEFAULT)
INSERT INTO users (name, email, password, branch_id, profile, position) VALUES
('Administrador Sistema', 'admin@empresa.cl', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 'cargador', 'Administrador'),
('Juan Aprobador', 'aprobador@empresa.cl', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 'aprobador', 'Jefe de Operaciones'),
('María Solicitante', 'solicitante@empresa.cl', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 'solicitante', 'Operador');

-- Máquinas demo
INSERT INTO machines (name, code, branch_id) VALUES
('Excavadora Komatsu PC200', 'EXC-001', 1),
('Camión Volvo FH', 'CAM-001', 1),
('Generador Caterpillar', 'GEN-001', 2),
('Retroexcavadora JCB', 'RET-001', 3);

-- Flujo aprobación sucursal 1
INSERT INTO approval_flows (branch_id, name) VALUES (1, 'Flujo Principal Casa Matriz');
INSERT INTO approval_flow_steps (flow_id, user_id, step_order) VALUES (1, 2, 1);

-- ============================================================
-- TABLA DE LOGS DEL SISTEMA
-- ============================================================
USE fuel_management;

CREATE TABLE IF NOT EXISTS system_logs (
    id           BIGINT AUTO_INCREMENT PRIMARY KEY,
    level        ENUM('INFO','WARNING','ERROR','CRITICAL') NOT NULL DEFAULT 'INFO',
    category     VARCHAR(30)  NOT NULL,
    action       VARCHAR(60)  NOT NULL,
    description  TEXT         NOT NULL,
    user_id      INT          DEFAULT NULL,
    user_name    VARCHAR(150) DEFAULT NULL,
    user_email   VARCHAR(150) DEFAULT NULL,
    branch_id    INT          DEFAULT NULL,
    ip_address   VARCHAR(45)  DEFAULT NULL,
    user_agent   VARCHAR(250) DEFAULT NULL,
    request_uri  VARCHAR(300) DEFAULT NULL,
    extra_data   JSON         DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_level      (level),
    INDEX idx_category   (category),
    INDEX idx_user_id    (user_id),
    INDEX idx_branch_id  (branch_id),
    INDEX idx_created_at (created_at),
    INDEX idx_action     (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ACTUALIZACIÓN: Soporte Microsoft OAuth 2.0
-- Ejecutar solo si ya existe la tabla users
-- ============================================================

-- Agregar columna ms_id para vincular con cuenta Microsoft
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS ms_id VARCHAR(100) DEFAULT NULL COMMENT 'Azure AD Object ID' AFTER email,
  MODIFY COLUMN password VARCHAR(255) DEFAULT NULL COMMENT 'NULL cuando usa SSO Microsoft',
  MODIFY COLUMN branch_id INT DEFAULT NULL COMMENT 'NULL hasta que admin asigne sucursal',
  MODIFY COLUMN profile ENUM('solicitante','aprobador','cargador') DEFAULT NULL COMMENT 'NULL hasta que admin asigne perfil';

-- Índice para búsqueda por ms_id
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_ms_id (ms_id);

-- Vista: usuarios pendientes de activación (sin perfil asignado)
CREATE OR REPLACE VIEW v_pending_users AS
  SELECT id, name, email, ms_id, position, created_at
  FROM users
  WHERE (profile IS NULL OR branch_id IS NULL)
    AND active = 0
  ORDER BY created_at DESC;
