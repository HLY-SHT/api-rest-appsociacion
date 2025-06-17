<?php


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json");
require 'vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;



$method = $_SERVER['REQUEST_METHOD'];
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$scriptPath = $_SERVER['SCRIPT_NAME'];
$path = substr($requestUri, strlen($scriptPath));
$parts = array_values(array_filter(explode('/', trim($path, '/'))));

$endpoint = $parts[0] ?? null;
$id = isset($parts[1]) && is_numeric($parts[1]) ? (int)$parts[1] : null;
$sub = $parts[2] ?? null;

$jwt_secret = 'l4t4r4r4ll3v41v3st12ll3n0d3c4sc4b3l3s';
/*
function connect_db()
{
    try {
        return new PDO("mysql:host=localhost;dbname=cannabis_db;charset=utf8", "root", "", [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    } catch (PDOException $e) {
        response(500, ['error' => 'Error DB: ' . $e->getMessage()]);
    }
}*/
/*
function connect_db()
{
    $host = getenv('DB_HOST');
    $db   = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
    $dsn  = "mysql:host=$host;dbname=$db;charset=utf8";

    try {
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    } catch (PDOException $e) {
        response(500, ['error' => 'Error DB: ' . $e->getMessage()]);
    }
}
*/
function connect_db()
{
    $url = getenv('DATABASE_URL');
    if (!$url) {
        response(500, ['error' => 'DATABASE_URL no configurada']);
        exit;
    }

    $parts = parse_url($url);
    $host = $parts['host'];
    $port = $parts['port'] ?? 5432;
    $user = $parts['user'];
    $pass = $parts['pass'];
    $db   = ltrim($parts['path'], '/');

    $dsn = "pgsql:host=$host;port=$port;dbname=$db";

    try {
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (PDOException $e) {
        response(500, ['error' => 'Error DB: ' . $e->getMessage()]);
        exit;
    }
}

function response($code, $data)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function get_club_id_from_token($jwt_secret)
{
    $headers = getallheaders();
    if (!isset($headers['Authorization'])) {
        response(401, ['error' => 'Token no enviado']);
    }

    $token = str_replace('Bearer ', '', $headers['Authorization']);

    try {
        $decoded = JWT::decode($token, new Key($jwt_secret, 'HS256'));
        return $decoded->club_id ?? null;
    } catch (Exception $e) {
        response(401, ['error' => 'Token inválido: ' . $e->getMessage()]);
    }
}

function require_admin($jwt_secret): void
{
    $headers = getallheaders();
    if (!isset($headers['Authorization'])) {
        response(401, ['error' => 'Token admin no enviado']);
    }

    $token = str_replace('Bearer ', '', $headers['Authorization']);

    try {
        $decoded = JWT::decode($token, new Key($jwt_secret, 'HS256'));
        if (empty($decoded->admin) || $decoded->admin !== true) {
            response(403, ['error' => 'No autorizado como admin']);
        }
    } catch (Exception $e) {
        response(401, ['error' => 'Token admin inválido']);
    }
}


$db = connect_db();

switch ($endpoint) {
    case 'login':
        if ($method !== 'POST') response(405, ['error' => 'Método no permitido']);
        $in = json_decode(file_get_contents('php://input'), true);
        if (empty($in['usuario']) || empty($in['contrasena'])) response(400, ['error' => 'Usuario y contraseña requeridos']);

        $stmt = $db->prepare("SELECT * FROM clubs WHERE usuario = :u");
        $stmt->execute([':u' => $in['usuario']]);
        $club = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($club && password_verify($in['contrasena'], $club['contrasena'])) {
            $payload = [
                'club_id' => $club['id'],
                'exp' => time() + (60 * 60 * 24) // 24 horas
            ];
            $jwt = JWT::encode($payload, $jwt_secret, 'HS256');
            response(200, [
                'token' => $jwt,
                'club_id' => $club['id'],
                'club_nombre' => $club['nombre'],
                'suscripcion'   => $club['suscripcion'] 
            ]);
        }

        response(401, ['error' => 'Credenciales inválidas']);
        break;

    case 'clubs':
        require_admin($jwt_secret);

        if ($method === 'GET') {
            if ($id) {
                // Obtener un club por su ID
                $stmt = $db->prepare("SELECT id, usuario, nombre FROM clubs WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $club = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($club) {
                    response(200, $club);
                } else {
                    response(404, ['error' => 'Club no encontrado']);
                }
            } else {
                // Listar todos los clubs
                $stmt = $db->query("SELECT id, usuario, nombre FROM clubs");
                response(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
        }

        if ($method === 'POST') {
            $in = json_decode(file_get_contents('php://input'), true);
            $stmt = $db->prepare("INSERT INTO clubs (usuario, contrasena, nombre) VALUES (:u, :c, :n)");
            $stmt->execute([
                ':u' => $in['usuario'],
                ':c' => password_hash($in['contrasena'], PASSWORD_DEFAULT),
                ':n' => $in['nombre']
            ]);
            response(201, ['message' => 'Club creado']);
        }

        if ($method === 'PUT' && $id) {
            $in = json_decode(file_get_contents('php://input'), true);
        
            // Si se intenta cambiar la suscripción a Basic...
            if (isset($in['suscripcion']) && $in['suscripcion'] === 'Basic') {
                // Contar socios actuales del club
                $stmtCount = $db->prepare("SELECT COUNT(*) as total FROM socios WHERE club_id = :cid");
                $stmtCount->execute([':cid' => $id]);
                $totalSocios = (int) $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
        
                if ($totalSocios > 50) {
                    response(400, ['error' => "No puedes bajar a Basic, tienes {$totalSocios} socios y el límite es 50"]);
                }
            }
        
            // Si no te llega 'suscripcion', puedes asignar un valor por defecto o permitir nulo:
            $suscripcion = isset($in['suscripcion']) ? $in['suscripcion'] : null;
        
            $stmt = $db->prepare("
                UPDATE clubs
                SET usuario     = :u,
                    nombre      = :n,
                    suscripcion = :s
                WHERE id = :id
            ");
            $stmt->execute([
                ':u'  => $in['usuario'],
                ':n'  => $in['nombre'],
                ':s'  => $suscripcion,
                ':id' => $id
            ]);
        
            response(200, ['message' => 'Club actualizado']);
        }
        
        

        if ($method === 'DELETE' && $id) {
            $stmt = $db->prepare("DELETE FROM clubs WHERE id = :id");
            $stmt->execute([':id' => $id]);
            response(200, ['message' => 'Club eliminado']);
        }
        break;

    case 'admin-login':
        if ($method !== 'POST') response(405, ['error' => 'Método no permitido']);

        $in = json_decode(file_get_contents('php://input'), true);

        if (empty($in['username']) || empty($in['password'])) {
            response(400, ['error' => 'Usuario y contraseña requeridos']);
        }

        $stmt = $db->prepare("SELECT * FROM admins WHERE username = :u");
        $stmt->execute([':u' => $in['username']]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin && password_verify($in['password'], $admin['password'])) {
            $payload = [
                'admin' => true,
                'admin_id' => $admin['id'],
                'exp' => time() + (60 * 60 * 24)
            ];
            $jwt = JWT::encode($payload, $jwt_secret, 'HS256');
            response(200, ['token' => $jwt]);
        }

        response(401, ['error' => 'Credenciales incorrectas']);
        break;

    case 'socios':
        $club_id = get_club_id_from_token($jwt_secret);

        if ($method === 'GET') {
            if ($id) {
                // GET /socios/:id → obtener un socio específico
                $stmt = $db->prepare("SELECT * FROM socios WHERE id = :id AND club_id = :cid");
                $stmt->execute([':id' => $id, ':cid' => $club_id]);
                $socio = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($socio) {
                    response(200, $socio);
                } else {
                    response(404, ['error' => 'Socio no encontrado']);
                }
            } else {
                // GET /socios → obtener todos los socios del club
                $stmt = $db->prepare("SELECT * FROM socios WHERE club_id = :cid");
                $stmt->execute([':cid' => $club_id]);
                response(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
        } elseif ($method === 'POST') {
            $in = json_decode(file_get_contents('php://input'), true);
        
            // 1. Obtener la suscripción del club
            $stmt = $db->prepare("SELECT suscripcion FROM clubs WHERE id = :cid");
            $stmt->execute([':cid' => $club_id]);
            $club = $stmt->fetch(PDO::FETCH_ASSOC);
        
            if (!$club) {
                response(403, ['error' => 'Club no válido']);
            }
        
            $suscripcion = $club['suscripcion'];
        
            // 2. Contar socios actuales
            $stmt = $db->prepare("SELECT COUNT(*) AS total FROM socios WHERE club_id = :cid");
            $stmt->execute([':cid' => $club_id]);
            $totalSocios = (int) $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
            // 3. Verificar límite por plan
            $limites = ['Basic' => 50, 'Medium' => 150, 'Premium' => PHP_INT_MAX];
        
            if (!isset($limites[$suscripcion])) {
                response(400, ['error' => 'Suscripción desconocida']);
            }
        
            if ($totalSocios >= $limites[$suscripcion]) {
                response(403, ['error' => "Has alcanzado el límite de socios para el plan $suscripcion"]);
            }
        
            // 4. Insertar socio
            $stmt = $db->prepare("INSERT INTO socios (club_id, id, nombre, apellidos, dni, cargo, cuota, monedero) 
                                  VALUES (:cid, :id, :n, :a, :d, :c, CURDATE(), 0)");
            $stmt->execute([
                ':cid' => $club_id,
                ':id'  => $in['id'],
                ':n'   => $in['nombre'],
                ':a'   => $in['apellidos'],
                ':d'   => $in['dni'],
                ':c'   => $in['cargo']
            ]);
        
            response(201, ['message' => 'Socio creado']);
        }
         elseif ($method === 'PUT' && $sub === 'cuota' && $id) {
            $socio_id = $id;
            $in = json_decode(file_get_contents('php://input'), true);
            $meses = isset($in['meses']) ? intval($in['meses']) : 0;

            // Obtener el socio
            $stmt = $db->prepare("
                SELECT cuota, monedero 
                  FROM socios 
                 WHERE id = :id 
                   AND club_id = :cid
            ");
            $stmt->execute([':id' => $socio_id, ':cid' => $club_id]);
            $socio = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$socio) {
                response(404, ['error' => 'Socio no encontrado']);
            }

            // Definir precios
            $precios = [1 => 5, 6 => 10, 12 => 20];
            if (!isset($precios[$meses])) {
                response(400, ['error' => 'Meses inválidos']);
            }
            $precio = $precios[$meses];

            if ($socio['monedero'] < $precio) {
                response(400, ['error' => 'Monedero insuficiente']);
            }

            // Actualizar cuota y restar del monedero
            $stmt = $db->prepare("
                UPDATE socios 
                   SET cuota = DATE_ADD(
                                 GREATEST(
                                   IFNULL(cuota, CURDATE()), 
                                   CURDATE()
                                 ), 
                                 INTERVAL :meses MONTH
                               ),
                       monedero = monedero - :precio
                 WHERE id = :id 
                   AND club_id = :cid
            ");
            $stmt->execute([
                ':meses'  => $meses,
                ':precio' => $precio,
                ':id'     => $socio_id,
                ':cid'    => $club_id
            ]);

            response(200, ['message' => 'Cuota renovada']);
        } elseif ($method === 'PUT' && $sub === 'monedero' && $id) {
            $socio_id = $id;
            $in = json_decode(file_get_contents('php://input'), true);
            $monto = isset($in['monto']) ? floatval($in['monto']) : 0;

            if ($monto <= 0) {
                response(400, ['error' => 'Monto inválido']);
            }

            // Verificar que existe el socio
            $stmt = $db->prepare("SELECT monedero FROM socios WHERE id = :id AND club_id = :cid");
            $stmt->execute([':id' => $socio_id, ':cid' => $club_id]);
            $s = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$s) {
                response(404, ['error' => 'Socio no encontrado']);
            }

            // Actualizar monedero sumando
            $stmt = $db->prepare("
                UPDATE socios 
                   SET monedero = monedero + :monto 
                 WHERE id = :id 
                   AND club_id = :cid
            ");
            $stmt->execute([
                ':monto' => $monto,
                ':id'    => $socio_id,
                ':cid'   => $club_id
            ]);

            response(200, ['message' => 'Monedero actualizado']);
        }
        // —————————————————————————————
        // 2) EDITAR DATOS DEL SOCIO → PUT /socios/:id
        // —————————————————————————————
        elseif ($method === 'PUT' && $id) {
            $in = json_decode(file_get_contents('php://input'), true);
            $stmt = $db->prepare("
                UPDATE socios 
                   SET nombre    = :n, 
                       apellidos = :a, 
                       dni       = :d, 
                       cargo     = :c 
                 WHERE id = :id 
                   AND club_id = :cid
            ");
            $stmt->execute([
                ':n'   => $in['nombre'],
                ':a'   => $in['apellidos'],
                ':d'   => $in['dni'],
                ':c'   => $in['cargo'],
                ':id'  => $id,
                ':cid' => $club_id
            ]);
            response(200, ['message' => 'Socio actualizado']);
        } elseif ($method === 'DELETE' && $id) {
            $stmt = $db->prepare("DELETE FROM socios WHERE id = :id AND club_id = :cid");
            $stmt->execute([':id' => $id, ':cid' => $club_id]);
            response(200, ['message' => 'Socio eliminado']);
        }

        break;

    case 'variedades':
        $club_id = get_club_id_from_token($jwt_secret);

        if ($method === 'GET') {
            if ($id) {
                // GET /variedades/:id → obtener una variedad específica del club
                $stmt = $db->prepare("SELECT * FROM variedades WHERE id = :id AND club_id = :cid");
                $stmt->execute([
                    ':id' => $id,
                    ':cid' => $club_id
                ]);
                $variedad = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($variedad) {
                    response(200, $variedad);
                } else {
                    response(404, ['error' => 'Variedad no encontrada']);
                }
            } else {
                // GET /variedades → listar todas las variedades del club
                $stmt = $db->prepare("SELECT * FROM variedades WHERE club_id = :cid");
                $stmt->execute([':cid' => $club_id]);
                response(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
        } elseif ($method === 'POST') {
            // Leemos el JSON del cliente (ahora debe incluir `id` en el cuerpo)
            $in = json_decode(file_get_contents('php://input'), true);

            // Validación mínima: id, nombre, categoria, etc.
            if (
                empty($in['id'])
                || empty($in['nombre'])
                || empty($in['categoria'])
                || !isset($in['precio'])
                || !isset($in['porcentaje'])
                || empty($in['predominancia'])
                || !isset($in['cantidad'])
            ) {
                response(400, ['error' => 'Faltan datos obligatorios']);
            }

            // Preparamos el INSERT incluyendo `id`
            $stmt = $db->prepare("INSERT INTO variedades 
                (club_id, id, categoria, nombre, precio, porcentaje, predominancia, cantidad) 
                VALUES 
                (:cid, :id, :cat, :nom, :pre, :por, :pred, :cant)");

            $stmt->execute([
                ':cid'  => $club_id,
                ':id'   => $in['id'],            // <-- usamos el id que envió Angular
                ':cat'  => $in['categoria'],
                ':nom'  => $in['nombre'],
                ':pre'  => $in['precio'],
                ':por'  => $in['porcentaje'],
                ':pred' => $in['predominancia'],
                ':cant' => $in['cantidad']
            ]);

            response(201, ['message' => 'Variedad creada']);
        } elseif ($method === 'PUT' && $sub === 'stock' && $id) {
            $variedad_id = $id;
            $in = json_decode(file_get_contents('php://input'), true);
            $gramos = isset($in['gramos']) ? floatval($in['gramos']) : 0;

            if ($gramos <= 0) {
                response(400, ['error' => 'Cantidad inválida']);
            }

            // Verificar que existe la variedad
            $stmt = $db->prepare("
                SELECT cantidad 
                  FROM variedades 
                 WHERE id = :id 
                   AND club_id = :cid
            ");
            $stmt->execute([':id' => $variedad_id, ':cid' => $club_id]);
            $v = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$v) {
                response(404, ['error' => 'Variedad no encontrada']);
            }

            // Sumar stock
            $stmt = $db->prepare("
                UPDATE variedades 
                   SET cantidad = cantidad + :gramos 
                 WHERE id = :id 
                   AND club_id = :cid
            ");
            $stmt->execute([
                ':gramos' => $gramos,
                ':id'     => $variedad_id,
                ':cid'    => $club_id
            ]);

            response(200, ['message' => 'Stock actualizado']);
        } elseif ($method === 'PUT' && $id) {
            $in = json_decode(file_get_contents('php://input'), true);
            $stmt = $db->prepare("
                UPDATE variedades 
                   SET categoria    = :cat,
                       nombre       = :nom,
                       precio       = :pre,
                       porcentaje   = :por,
                       predominancia= :pred,
                       cantidad     = :cant
                 WHERE id = :id 
                   AND club_id = :cid
            ");
            $stmt->execute([
                ':cat'  => $in['categoria'],
                ':nom'  => $in['nombre'],
                ':pre'  => $in['precio'],
                ':por'  => $in['porcentaje'],
                ':pred' => $in['predominancia'],
                ':cant' => $in['cantidad'],
                ':id'   => $id,
                ':cid'  => $club_id
            ]);
            response(200, ['message' => 'Variedad actualizada']);
        } elseif ($method === 'DELETE' && $id) {
            $stmt = $db->prepare("DELETE FROM variedades WHERE id = :id AND club_id = :cid");
            $stmt->execute([':id' => $id, ':cid' => $club_id]);
            response(200, ['message' => 'Variedad eliminada']);
        }
        break;

    case 'dispensaciones':
        $club_id = get_club_id_from_token($jwt_secret);

        if ($method === 'GET') {
            if ($id) {
                // GET /dispensaciones/:id → obtener una dispensación específica
                // Con JOIN para traer nombre/id del socio y nombre/id/categoría de la variedad
                $stmt = $db->prepare("
                    SELECT 
                        d.id AS dispensacion_id,
                        d.socio_id,
                        s.nombre AS socio_nombre,
                        s.apellidos AS socio_apellidos,
                        d.variedad_id,
                        v.nombre AS variedad_nombre,
                        v.categoria AS variedad_categoria,
                        d.dinero,
                        d.cantidad,
                        d.fecha
                    FROM dispensaciones d
                    JOIN socios   s ON (d.club_id = s.club_id AND d.socio_id = s.id)
                    JOIN variedades v ON (d.club_id = v.club_id AND d.variedad_id = v.id)
                    WHERE d.id = :id AND d.club_id = :cid
                ");
                $stmt->execute([':id' => $id, ':cid' => $club_id]);
                $disp = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($disp) {
                    response(200, $disp);
                } else {
                    response(404, ['error' => 'Dispensación no encontrada']);
                }
            } else {
                // GET /dispensaciones → listar todas las dispensaciones del club
                // Con JOINs para traer socio y variedad completas
                $stmt = $db->prepare("
                    SELECT 
                        d.id AS dispensacion_id,
                        d.socio_id,
                        s.nombre AS socio_nombre,
                        s.apellidos AS socio_apellidos,
                        d.variedad_id,
                        v.nombre AS variedad_nombre,
                        v.categoria AS variedad_categoria,
                        d.dinero,
                        d.cantidad,
                        d.fecha
                    FROM dispensaciones d
                    JOIN socios   s ON (d.club_id = s.club_id AND d.socio_id = s.id)
                    JOIN variedades v ON (d.club_id = v.club_id AND d.variedad_id = v.id)
                    WHERE d.club_id = :cid
                ");
                $stmt->execute([':cid' => $club_id]);
                response(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
        } elseif ($method === 'POST') {
            $in = json_decode(file_get_contents('php://input'), true);

            // 1) Validaciones básicas
            if (empty($in['socio_id']) || empty($in['variedad_id']) || !isset($in['dinero'])) {
                response(400, ['error' => 'Faltan datos obligatorios']);
            }

            $var = $db->prepare("
                SELECT * 
                FROM variedades 
                WHERE id = :vid AND club_id = :cid 
                FOR UPDATE
            ");
            $var->execute([':vid' => $in['variedad_id'], ':cid' => $club_id]);
            $variedad = $var->fetch(PDO::FETCH_ASSOC);
            if (!$variedad) {
                response(404, ['error' => 'Variedad no encontrada']);
            }

            $cantidadCalculada = round($in['dinero'] / $variedad['precio'], 2);

            $soc = $db->prepare("
                SELECT monedero 
                FROM socios 
                WHERE id = :sid AND club_id = :cid 
                FOR UPDATE
            ");
            $soc->execute([':sid' => $in['socio_id'], ':cid' => $club_id]);
            $socio = $soc->fetch(PDO::FETCH_ASSOC);
            if (!$socio) {
                response(404, ['error' => 'Socio no encontrado']);
            }
            if ($socio['monedero'] < $in['dinero']) {
                response(400, ['error' => 'Monedero insuficiente']);
            }

            try {
                $db->beginTransaction();

                // 2) Descontar stock de la variedad
                $db->prepare("
                    UPDATE variedades 
                       SET cantidad = cantidad - :c 
                     WHERE id = :vid AND club_id = :cid
                ")->execute([
                    ':c'   => $cantidadCalculada,
                    ':vid' => $in['variedad_id'],
                    ':cid' => $club_id
                ]);

                // 3) Descontar dinero del socio
                $db->prepare("
                    UPDATE socios 
                       SET monedero = monedero - :d 
                     WHERE id = :sid AND club_id = :cid
                ")->execute([
                    ':d'   => $in['dinero'],
                    ':sid' => $in['socio_id'],
                    ':cid' => $club_id
                ]);

                // 4) Calcular el nuevo ID de dispensación dentro de este club
                $stmtMax = $db->prepare("
                    SELECT MAX(id) AS max_id 
                      FROM dispensaciones 
                     WHERE club_id = :cid 
                     FOR UPDATE
                ");
                $stmtMax->execute([':cid' => $club_id]);
                $row = $stmtMax->fetch(PDO::FETCH_ASSOC);
                $nuevoId = $row && $row['max_id'] !== null ? ($row['max_id'] + 1) : 1;

                // 5) Insertar la nueva dispensación, incluyendo el campo `id`
                $db->prepare("
                    INSERT INTO dispensaciones 
                      (club_id, id, socio_id, fecha, variedad_id, nombre_variedad, dinero, cantidad)
                    VALUES 
                      (:cid, :nid, :sid, NOW(), :vid, :nombre, :dinero, :cantidad)
                ")->execute([
                    ':cid'     => $club_id,
                    ':nid'     => $nuevoId,
                    ':sid'     => $in['socio_id'],
                    ':vid'     => $in['variedad_id'],
                    ':nombre'  => $variedad['nombre'],
                    ':dinero'  => $in['dinero'],
                    ':cantidad' => $cantidadCalculada
                ]);

                $db->commit();
                response(201, ['message' => 'Dispensación registrada']);
            } catch (Exception $e) {
                $db->rollBack();
                response(500, ['error' => $e->getMessage()]);
            }
        } elseif ($method === 'DELETE' && $id) {
            $stmt = $db->prepare("DELETE FROM dispensaciones WHERE id = :id AND club_id = :cid");
            $stmt->execute([':id' => $id, ':cid' => $club_id]);
            response(200, ['message' => 'Dispensación eliminada']);
        }
        break;

        /*
        case 'eventos':
            $club_id = get_club_id_from_token($jwt_secret);
        
            // 1) GET /eventos → listar eventos del club
            if ($method === 'GET') {
                // Si el plan es Basic, no devolver nada (o devolver 403)
                $stmtPlan = $db->prepare("SELECT suscripcion FROM clubs WHERE id = :cid");
                $stmtPlan->execute([':cid' => $club_id]);
                $info = $stmtPlan->fetch(PDO::FETCH_ASSOC);
                if ($info['suscripcion'] === 'Basic') {
                    response(403, ['error' => 'El plan Basic no ofrece servicio de noticias/eventos']);
                }
        
                $stmt = $db->prepare("
                  SELECT id, titulo, descripcion, fecha, hora
                    FROM eventos
                   WHERE club_id = :cid
                   ORDER BY fecha DESC, hora DESC
                ");
                $stmt->execute([':cid' => $club_id]);
                response(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
            }
        
            // 2) POST /eventos → crear evento (solo Medium o Premium)
            elseif ($method === 'POST') {
                $stmtPlan = $db->prepare("SELECT suscripcion FROM clubs WHERE id = :cid");
                $stmtPlan->execute([':cid' => $club_id]);
                $info = $stmtPlan->fetch(PDO::FETCH_ASSOC);
                if (!in_array($info['suscripcion'], ['Medium','Premium'])) {
                    response(403, ['error' => 'Solo plan Medium o Premium permite crear eventos']);
                }
        
                $in = json_decode(file_get_contents('php://input'), true);
                if (empty($in['titulo']) || empty($in['fecha']) || empty($in['hora'])) {
                    response(400, ['error' => 'Título, fecha y hora son obligatorios']);
                }
        
                $stmt = $db->prepare("
                  INSERT INTO eventos (club_id, titulo, descripcion, fecha, hora)
                  VALUES (:cid, :tit, :desc, :fec, :hor)
                ");
                $stmt->execute([
                  ':cid'  => $club_id,
                  ':tit'  => $in['titulo'],
                  ':desc' => $in['descripcion'] ?? '',
                  ':fec'  => $in['fecha'],
                  ':hor'  => $in['hora']
                ]);
                response(201, ['message' => 'Evento creado']);
            }
        
            // 3) PUT /eventos/:id → actualizar evento (solo Medium o Premium)
            elseif ($method === 'PUT' && $id) {
                $stmtPlan = $db->prepare("SELECT suscripcion FROM clubs WHERE id = :cid");
                $stmtPlan->execute([':cid' => $club_id]);
                $info = $stmtPlan->fetch(PDO::FETCH_ASSOC);
                if (!in_array($info['suscripcion'], ['Medium','Premium'])) {
                    response(403, ['error' => 'No autorizado para editar eventos']);
                }
        
                $in = json_decode(file_get_contents('php://input'), true);
                $stmt = $db->prepare("
                  UPDATE eventos
                     SET titulo      = :tit,
                         descripcion = :desc,
                         fecha       = :fec,
                         hora        = :hor
                   WHERE id = :id AND club_id = :cid
                ");
                $stmt->execute([
                  ':tit'  => $in['titulo'],
                  ':desc' => $in['descripcion'] ?? '',
                  ':fec'  => $in['fecha'],
                  ':hor'  => $in['hora'],
                  ':id'   => $id,
                  ':cid'  => $club_id
                ]);
                response(200, ['message' => 'Evento actualizado']);
            }
        
            // 4) DELETE /eventos/:id → eliminar evento (solo Medium o Premium)
            elseif ($method === 'DELETE' && $id) {
                $stmtPlan = $db->prepare("SELECT suscripcion FROM clubs WHERE id = :cid");
                $stmtPlan->execute([':cid' => $club_id]);
                $info = $stmtPlan->fetch(PDO::FETCH_ASSOC);
                if (!in_array($info['suscripcion'], ['Medium','Premium'])) {
                    response(403, ['error' => 'No autorizado para eliminar eventos']);
                }
        
                $stmt = $db->prepare("
                  DELETE FROM eventos
                   WHERE id = :id AND club_id = :cid
                ");
                $stmt->execute([':id' => $id, ':cid' => $club_id]);
                response(200, ['message' => 'Evento eliminado']);
            }
            break;
            */
            
            case 'dashboard':
                if ($method === 'GET') {
                    $club_id = get_club_id_from_token($jwt_secret);
            
                    // 1) Comprobar plan del club
                    $stmtPlan = $db->prepare("SELECT suscripcion FROM clubs WHERE id = :cid");
                    $stmtPlan->execute([':cid' => $club_id]);
                    $info = $stmtPlan->fetch(PDO::FETCH_ASSOC);
                    $plan = $info['suscripcion'] ?? '';
            
                    // Plan Basic NO tiene dashboard
                    if ($plan !== 'Premium') {
                        response(403, ['error' => 'Tu plan no incluye acceso al Dashboard']);
                    }
            
                    // 2) Totales
                    $totales = [
                      'socios'        => (int)$db->query("SELECT COUNT(*) FROM socios WHERE club_id = $club_id")->fetchColumn(),
                      'variedades'    => (int)$db->query("SELECT COUNT(*) FROM variedades WHERE club_id = $club_id")->fetchColumn(),
                      'dispensaciones'=> (int)$db->query("SELECT COUNT(*) FROM dispensaciones WHERE club_id = $club_id")->fetchColumn(),
                      'ingresos'      => (float)$db->query("SELECT IFNULL(SUM(dinero),0) FROM dispensaciones WHERE club_id = $club_id")->fetchColumn(),
                    ];
            
                    // 3) Ingresos mensuales
                    $mensual = $db->query("
                      SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes, SUM(dinero) AS total
                        FROM dispensaciones
                       WHERE club_id = $club_id
                       GROUP BY mes
                       ORDER BY mes
                    ")->fetchAll(PDO::FETCH_ASSOC);
            
                    // 4) Top 10 socios este mes
                    $topSocios = $db->query("
                      SELECT s.nombre, s.apellidos, COUNT(*) AS total
                        FROM dispensaciones d
                        JOIN socios s ON d.socio_id = s.id AND d.club_id = s.club_id
                       WHERE d.club_id = $club_id
                         AND DATE_FORMAT(d.fecha, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
                       GROUP BY d.socio_id
                       ORDER BY total DESC
                       LIMIT 10
                    ")->fetchAll(PDO::FETCH_ASSOC);
            
                    // 5) Top 10 variedades
                    $topVariedades = $db->query("
                      SELECT v.nombre, COUNT(*) AS total
                        FROM dispensaciones d
                        JOIN variedades v ON d.variedad_id = v.id AND d.club_id = v.club_id
                       WHERE d.club_id = $club_id
                       GROUP BY d.variedad_id
                       ORDER BY total DESC
                       LIMIT 10
                    ")->fetchAll(PDO::FETCH_ASSOC);
            
                    // 6) Socios con cuota vencida
                    $caducados = $db->query("
                      SELECT id, nombre, apellidos, cuota
                        FROM socios
                       WHERE club_id = $club_id
                         AND cuota < CURDATE()
                    ")->fetchAll(PDO::FETCH_ASSOC);
            
                    response(200, [
                      'totales'       => $totales,
                      'mensual'       => $mensual,
                      'topSocios'     => $topSocios,
                      'topVariedades' => $topVariedades,
                      'caducados'     => $caducados
                    ]);
                }
                break;

                /*
                case 'login-socio':
                    if ($method !== 'POST') response(405, ['error'=>'Método no permitido']);
                    $in = json_decode(file_get_contents('php://input'), true);
                    if (empty($in['dni']) || empty($in['password'])) {
                      response(400, ['error'=>'DNI y contraseña requeridos']);
                    }
                  
                    // 1) Buscar socio por DNI en el club
                    $stmt = $db->prepare("
                      SELECT id, club_id, nombre, apellidos, password, monedero, cuota
                        FROM socios 
                       WHERE dni = :dni AND club_id = :cid
                    ");
                    $stmt->execute([':dni'=>$in['dni'], ':cid'=>$club_id]);
                    $socio = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$socio || !password_verify($in['password'], $socio['password'])) {
                      response(401, ['error'=>'Credenciales inválidas']);
                    }
                  
                    // 2) Crear JWT con socio_id y club_id
                    $payload = [
                      'socio_id' => $socio['id'],
                      'club_id'  => $socio['club_id'],
                      'exp'      => time() + 86400
                    ];
                    $token = JWT::encode($payload, $jwt_secret, 'HS256');
                  
                    response(200, [
                      'token'     => $token,
                      'socio_id'  => $socio['id'],
                      'nombre'    => $socio['nombre'],
                      'apellidos' => $socio['apellidos'],
                      'monedero' => $socio['monedero'],
                      'cuota' => $socio['cuota']
                    ]);
                  break;
                  */
                  
            
              
            

    default:
        response(404, ['error' => 'Endpoint no encontrado']);
}
