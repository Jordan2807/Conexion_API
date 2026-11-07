<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();

require_once __DIR__ . '/config.php';

// ==========================================
// FUNCIONES GENERALES
// ==========================================

function escapar($valor)
{
    return htmlspecialchars(
        (string)$valor,
        ENT_QUOTES,
        'UTF-8'
    );
}

// ==========================================
// CONEXION CON AULA API LAB
// TODAS LAS SOLICITUDES SE HACEN DESDE PHP
// ==========================================

function llamarApi($endpoint, $metodo = 'GET', $datos = null, $requiereClave = true)
{
    if (!function_exists('curl_init')) {
        return [
            'http' => 0,
            'json' => null,
            'error' => 'La extension cURL no esta habilitada en PHP.'
        ];
    }

    // Verificar API Key
    if (
        $requiereClave &&
        (
            !defined('API_KEY') ||
            API_KEY === '' ||
            API_KEY === 'PEGAR_AQUI_TU_API_KEY'
        )
    ) {
        return [
            'http' => 0,
            'json' => null,
            'error' => 'Debes configurar tu API Key en config.php.'
        ];
    }

    $url = rtrim(API_BASE_URL, '/') . '/' . $endpoint;

    $headers = [
        'Accept: application/json'
    ];

    if ($requiereClave) {
        $headers[] = 'X-API-Key: ' . API_KEY;
    }

    if ($metodo === 'POST') {
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($url);

    $opciones = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ];

    if ($metodo === 'POST') {
        $json = json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE
        );

        $opciones[CURLOPT_POST] = true;
        $opciones[CURLOPT_POSTFIELDS] = $json;
    }

    curl_setopt_array($ch, $opciones);

    $respuesta = curl_exec($ch);

    $codigo = curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    $error = curl_error($ch);

    curl_close($ch);

    // Error de conexion
    if ($respuesta === false) {
        return [
            'http' => $codigo,
            'json' => null,
            'error' => 'Error de conexion: ' . $error
        ];
    }

    // Convertir respuesta JSON
    $jsonRespuesta = json_decode($respuesta, true);

    if (!is_array($jsonRespuesta)) {
        return [
            'http' => $codigo,
            'json' => null,
            'error' => 'La API devolvio una respuesta JSON invalida.'
        ];
    }

    return [
        'http' => $codigo,
        'json' => $jsonRespuesta,
        'error' => null
    ];
}

// ==========================================
// VERIFICAR RESPUESTA DE LA API
// ==========================================

function apiCorrecta($respuesta)
{
    return
        $respuesta['error'] === null &&
        $respuesta['http'] >= 200 &&
        $respuesta['http'] < 300 &&
        !empty($respuesta['json']['success']);
}

function mensajeError($respuesta)
{
    if (!empty($respuesta['error'])) {
        return $respuesta['error'];
    }

    return $respuesta['json']['error']['message']
        ?? 'Ocurrio un error al comunicarse con la API.';
}

function obtenerDatos($respuesta)
{
    return $respuesta['json']['data'] ?? [];
}

// ==========================================
// INICIALIZAR SESION Y CARRITO
// ==========================================

if (!isset($_SESSION['carrito'])) {
    $_SESSION['carrito'] = [];
}

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$mensaje = $_SESSION['mensaje'] ?? '';
unset($_SESSION['mensaje']);

$errorAccion = '';

// ==========================================
// PROCESAR FORMULARIOS
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Validar token de seguridad
    if (
        !isset($_POST['csrf']) ||
        !hash_equals($_SESSION['csrf'], $_POST['csrf'])
    ) {
        $errorAccion = 'La solicitud no es valida. Recarga la pagina.';
    } else {

        $accion = $_POST['accion'] ?? '';

        // ======================================
        // AGREGAR PRODUCTO AL CARRITO
        // ======================================

        if ($accion === 'agregar') {

            $id = filter_var(
                $_POST['producto_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            $cantidad = filter_var(
                $_POST['cantidad'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$id || $id < 1) {
                $errorAccion = 'El producto seleccionado no es valido.';

            } elseif (!$cantidad || $cantidad < 1 || $cantidad > 100) {
                $errorAccion = 'La cantidad debe estar entre 1 y 100.';

            } else {

                // Consultar nuevamente el producto en la API
                $respuesta = llamarApi(
                    'product.php?id=' . $id
                );

                if (apiCorrecta($respuesta)) {

                    $producto = obtenerDatos($respuesta);

                    if (isset($producto['product'])) {
                        $producto = $producto['product'];
                    }

                    $stock = (int)($producto['stock'] ?? 0);

                    $cantidadActual = $_SESSION['carrito'][$id] ?? 0;

                    if ($stock < 1) {
                        $errorAccion = 'Este producto no tiene existencias.';

                    } elseif (($cantidadActual + $cantidad) > $stock) {
                        $errorAccion = 'No hay suficiente inventario disponible.';

                    } else {

                        $_SESSION['carrito'][$id] =
                            $cantidadActual + $cantidad;

                        $_SESSION['mensaje'] =
                            'Producto agregado correctamente al carrito.';

                        header('Location: index.php#carrito');
                        exit;
                    }

                } else {
                    $errorAccion = mensajeError($respuesta);
                }
            }
        }

        // ======================================
        // ELIMINAR PRODUCTO DEL CARRITO
        // ======================================

        elseif ($accion === 'eliminar') {

            $id = (int)($_POST['producto_id'] ?? 0);

            unset($_SESSION['carrito'][$id]);

            $_SESSION['mensaje'] =
                'Producto eliminado del carrito.';

            header('Location: index.php#carrito');
            exit;
        }

        // ======================================
        // VACIAR CARRITO
        // ======================================

        elseif ($accion === 'vaciar') {

            $_SESSION['carrito'] = [];

            $_SESSION['mensaje'] =
                'El carrito se ha vaciado correctamente.';

            header('Location: index.php#carrito');
            exit;
        }

        // ======================================
        // CREAR PEDIDO
        // ======================================

        elseif ($accion === 'crear_pedido') {

            $nombre = trim($_POST['customer_name'] ?? '');
            $notas = trim($_POST['notes'] ?? '');

            $longitudNombre = function_exists('mb_strlen')
                ? mb_strlen($nombre, 'UTF-8')
                : strlen($nombre);

            $longitudNotas = function_exists('mb_strlen')
                ? mb_strlen($notas, 'UTF-8')
                : strlen($notas);

            if ($longitudNombre < 2 || $longitudNombre > 100) {

                $errorAccion =
                    'El nombre debe tener entre 2 y 100 caracteres.';

            } elseif ($longitudNotas > 500) {

                $errorAccion =
                    'Las notas no pueden superar los 500 caracteres.';

            } elseif (empty($_SESSION['carrito'])) {

                $errorAccion =
                    'Debes agregar productos antes de crear un pedido.';

            } elseif (count($_SESSION['carrito']) > 20) {

                $errorAccion =
                    'El pedido no puede superar los 20 productos diferentes.';

            } else {

                // Construir los productos del pedido
                $items = [];

                foreach ($_SESSION['carrito'] as $id => $cantidad) {

                    $id = (int)$id;
                    $cantidad = (int)$cantidad;

                    if (
                        $id < 1 ||
                        $cantidad < 1 ||
                        $cantidad > 100
                    ) {
                        $errorAccion =
                            'Uno de los productos tiene datos invalidos.';
                        break;
                    }

                    $items[] = [
                        'product_id' => $id,
                        'quantity' => $cantidad
                    ];
                }

                if ($errorAccion === '') {

                    $datosPedido = [
                        'customer_name' => $nombre,
                        'notes' => $notas,
                        'items' => $items
                    ];

                    // Enviar pedido mediante POST desde PHP
                    $respuesta = llamarApi(
                        'orders.php',
                        'POST',
                        $datosPedido
                    );

                    if (apiCorrecta($respuesta)) {

                        $pedido = obtenerDatos($respuesta);

                        $idPedido = (int)($pedido['id'] ?? 0);

                        $_SESSION['carrito'] = [];

                        $_SESSION['mensaje'] =
                            'Pedido creado correctamente. ID: ' .
                            $idPedido .
                            '. Total: ₡' .
                            ($pedido['total'] ?? 'Consulte el detalle.');

                        if ($idPedido > 0) {
                            header(
                                'Location: index.php?pedido=' .
                                $idPedido . '#pedidos'
                            );
                        } else {
                            header('Location: index.php#pedidos');
                        }

                        exit;

                    } else {

                        $errorAccion = mensajeError($respuesta);
                    }
                }
            }
        }
    }
}

// ==========================================
// CONSULTAR ESTADO DE LA API
// ENDPOINT PUBLICO
// ==========================================

$respuestaHealth = llamarApi(
    'health.php',
    'GET',
    null,
    false
);

// ==========================================
// CONSULTAR PRODUCTOS
// ==========================================

$busqueda = trim($_GET['q'] ?? '');
$categoria = trim($_GET['category'] ?? '');

$pagina = max(1, (int)($_GET['page'] ?? 1));

$parametrosProductos = [
    'page' => $pagina,
    'per_page' => 10
];

if ($busqueda !== '') {
    $parametrosProductos['q'] = $busqueda;
}

if ($categoria !== '') {
    $parametrosProductos['category'] = $categoria;
}

$respuestaProductos = llamarApi(
    'products.php?' . http_build_query($parametrosProductos)
);

$productos = obtenerDatos($respuestaProductos);

$metaProductos =
    $respuestaProductos['json']['meta'] ?? [];

// ==========================================
// CONSULTAR DETALLE DE PRODUCTO
// ==========================================

$idProducto = filter_var(
    $_GET['producto'] ?? null,
    FILTER_VALIDATE_INT
);

$respuestaDetalleProducto = null;

if ($idProducto && $idProducto > 0) {

    $respuestaDetalleProducto = llamarApi(
        'product.php?id=' . $idProducto
    );
}

// ==========================================
// CONSULTAR HISTORIAL DE PEDIDOS
// ==========================================

$paginaPedidos = max(1, (int)($_GET['opage'] ?? 1));

$respuestaPedidos = llamarApi(
    'orders.php?page=' . $paginaPedidos . '&per_page=10'
);

$pedidos = obtenerDatos($respuestaPedidos);

$metaPedidos =
    $respuestaPedidos['json']['meta'] ?? [];

if (isset($pedidos['orders'])) {
    $pedidos = $pedidos['orders'];
}

if (!is_array($pedidos)) {
    $pedidos = [];
}

// ==========================================
// CONSULTAR DETALLE DE PEDIDO
// ==========================================

$idPedido = filter_var(
    $_GET['pedido'] ?? null,
    FILTER_VALIDATE_INT
);

$respuestaDetallePedido = null;

if ($idPedido && $idPedido > 0) {

    $respuestaDetallePedido = llamarApi(
        'order.php?id=' . $idPedido
    );
}

// ==========================================
// CONSULTAR PRODUCTOS DEL CARRITO
// ==========================================

$productosCarrito = [];

foreach ($_SESSION['carrito'] as $id => $cantidad) {

    $respuesta = llamarApi(
        'product.php?id=' . (int)$id
    );

    if (apiCorrecta($respuesta)) {

        $producto = obtenerDatos($respuesta);

        if (isset($producto['product'])) {
            $producto = $producto['product'];
        }

        $productosCarrito[] = [
            'producto' => $producto,
            'cantidad' => $cantidad
        ];
    }
}

$datosHealth = obtenerDatos($respuestaHealth);

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>AulaStore | Tienda Tecnológica</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f3f5f8;
            color: #253047;
        }

        header {
            background: #172b4d;
            color: white;
            padding: 25px 8%;
        }

        header h1 {
            margin: 0 0 8px;
        }

        nav {
            background: #24436f;
            padding: 15px 8%;
        }

        nav a {
            color: white;
            text-decoration: none;
            margin-right: 20px;
            font-weight: bold;
        }

        main {
            width: 90%;
            max-width: 1200px;
            margin: 25px auto;
        }

        section {
            background: white;
            padding: 25px;
            margin-bottom: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }

        h2 {
            color: #172b4d;
            margin-top: 0;
        }

        .productos {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 18px;
        }

        .producto {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 18px;
        }

        .producto h3 {
            margin-top: 0;
        }

        .precio {
            color: #16834a;
            font-size: 20px;
            font-weight: bold;
        }

        button {
            background: #2458a6;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 5px;
            cursor: pointer;
        }

        button:hover {
            background: #173d78;
        }

        input, textarea {
            width: 100%;
            padding: 10px;
            margin: 7px 0 15px;
            border: 1px solid #ccc;
            border-radius: 5px;
        }

        input[type="number"] {
            max-width: 100px;
        }

        label {
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th, td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: #edf1f7;
        }

        .mensaje {
            background: #d9f5e3;
            color: #176b3a;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .error {
            background: #ffe1e1;
            color: #a12626;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .estado {
            padding: 15px;
            background: #edf1f7;
            border-radius: 7px;
            margin-top: 10px;
        }

        .enlace {
            color: #2458a6;
            font-weight: bold;
            text-decoration: none;
        }

        .paginacion {
            margin-top: 20px;
        }

        .paginacion a {
            display: inline-block;
            padding: 8px 12px;
            background: #edf1f7;
            color: #172b4d;
            margin: 4px;
            text-decoration: none;
            border-radius: 5px;
        }

        footer {
            text-align: center;
            padding: 25px;
            background: #172b4d;
            color: white;
        }

        @media(max-width: 600px) {

            nav a {
                display: inline-block;
                margin: 5px;
            }

            section {
                padding: 15px;
                overflow-x: auto;
            }

            table {
                min-width: 500px;
            }
        }

    </style>

</head>

<body>

<header>

    <h1>AulaStore</h1>

    <p>Aplicación web conectada a Aula API Lab</p>

</header>

<nav>

    <a href="#inicio">Inicio</a>

    <a href="#productos">Productos</a>

    <a href="#carrito">Carrito</a>

    <a href="#pedidos">Pedidos</a>

</nav>

<main>

    <?php if ($mensaje !== ''): ?>

        <div class="mensaje">
            <?= escapar($mensaje) ?>
        </div>

    <?php endif; ?>

    <?php if ($errorAccion !== ''): ?>

        <div class="error">
            <?= escapar($errorAccion) ?>
        </div>

    <?php endif; ?>


    <!-- ==================================
         ESTADO DE CONEXION
    =================================== -->

    <section id="inicio">

        <h2>Estado de la API</h2>

        <?php if (apiCorrecta($respuestaHealth)): ?>

            <div class="estado">

                <strong>Conexion correcta</strong>

                <p>
                    Estado:
                    <?= escapar($datosHealth['status'] ?? 'Disponible') ?>
                </p>

                <p>
                    Servicio:
                    <?= escapar($datosHealth['service'] ?? 'Aula API Lab') ?>
                </p>

                <p>
                    Version:
                    <?= escapar($datosHealth['version'] ?? 'No especificada') ?>
                </p>

            </div>

        <?php else: ?>

            <div class="error">

                No fue posible conectar con Aula API Lab.

                <?= escapar(mensajeError($respuestaHealth)) ?>

            </div>

        <?php endif; ?>

    </section>


    <!-- ==================================
         CATALOGO DE PRODUCTOS
    =================================== -->

    <section id="productos">

        <h2>Catalogo de productos</h2>

        <form method="GET" action="index.php">

            <label>Buscar producto:</label>

            <input
                type="text"
                name="q"
                placeholder="Nombre, descripcion o SKU"
                value="<?= escapar($busqueda) ?>"
            >

            <label>Categoria:</label>

            <input
                type="text"
                name="category"
                placeholder="Ejemplo: Perifericos"
                value="<?= escapar($categoria) ?>"
            >

            <input type="hidden" name="page" value="1">

            <button type="submit">Buscar productos</button>

        </form>

        <?php if (apiCorrecta($respuestaProductos)): ?>

            <?php if (!empty($productos)): ?>

                <div class="productos">

                    <?php foreach ($productos as $producto): ?>

                        <div class="producto">

                            <h3>
                                <?= escapar($producto['name'] ?? 'Producto') ?>
                            </h3>

                            <p>
                                SKU:
                                <?= escapar($producto['sku'] ?? 'N/D') ?>
                            </p>

                            <p>
                                Categoria:
                                <?= escapar($producto['category'] ?? 'N/D') ?>
                            </p>

                            <p>
                                <?= escapar($producto['description'] ?? '') ?>
                            </p>

                            <p class="precio">

                                ₡<?= number_format(
                                    (float)($producto['price'] ?? 0),
                                    2
                                ) ?>

                            </p>

                            <p>

                                Existencias:
                                <?= escapar($producto['stock'] ?? 0) ?>

                            </p>

                            <a
                                class="enlace"
                                href="index.php?producto=<?= (int)$producto['id'] ?>#detalle-producto"
                            >
                                Ver detalles
                            </a>

                            <form method="POST" action="index.php#carrito">

                                <input
                                    type="hidden"
                                    name="csrf"
                                    value="<?= escapar($_SESSION['csrf']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="accion"
                                    value="agregar"
                                >

                                <input
                                    type="hidden"
                                    name="producto_id"
                                    value="<?= (int)$producto['id'] ?>"
                                >

                                <label>Cantidad:</label>

                                <input
                                    type="number"
                                    name="cantidad"
                                    value="1"
                                    min="1"
                                    max="100"
                                    required
                                >

                                <button type="submit">
                                    Agregar al carrito
                                </button>

                            </form>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <p>No se encontraron productos.</p>

            <?php endif; ?>

            <!-- PAGINACION DE PRODUCTOS -->

            <?php

            $totalPaginas = max(
                1,
                (int)($metaProductos['total_pages'] ?? 1)
            );

            ?>

            <?php if ($totalPaginas > 1): ?>

                <div class="paginacion">

                    <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>

                        <?php

                        $parametros = [
                            'page' => $i,
                            'per_page' => 10
                        ];

                        if ($busqueda !== '') {
                            $parametros['q'] = $busqueda;
                        }

                        if ($categoria !== '') {
                            $parametros['category'] = $categoria;
                        }

                        ?>

                        <a href="index.php?<?= escapar(
                            http_build_query($parametros)
                        ) ?>#productos">

                            <?= $i ?>

                        </a>

                    <?php endfor; ?>

                </div>

            <?php endif; ?>

        <?php else: ?>

            <div class="error">

                Error al consultar los productos:

                <?= escapar(mensajeError($respuestaProductos)) ?>

            </div>

        <?php endif; ?>

    </section>


    <!-- ==================================
         DETALLE DE PRODUCTO
    =================================== -->

    <?php if ($idProducto && $idProducto > 0): ?>

        <section id="detalle-producto">

            <h2>Detalle del producto</h2>

            <?php if (
                $respuestaDetalleProducto !== null &&
                apiCorrecta($respuestaDetalleProducto)
            ): ?>

                <?php

                $detalle = obtenerDatos($respuestaDetalleProducto);

                if (isset($detalle['product'])) {
                    $detalle = $detalle['product'];
                }

                ?>

                <h3><?= escapar($detalle['name'] ?? '') ?></h3>

                <p>
                    SKU: <?= escapar($detalle['sku'] ?? 'N/D') ?>
                </p>

                <p>
                    Categoria:
                    <?= escapar($detalle['category'] ?? 'N/D') ?>
                </p>

                <p>
                    Descripcion:
                    <?= escapar($detalle['description'] ?? '') ?>
                </p>

                <p>
                    Precio:
                    ₡<?= number_format(
                        (float)($detalle['price'] ?? 0),
                        2
                    ) ?>
                </p>

                <p>
                    Existencias:
                    <?= escapar($detalle['stock'] ?? 0) ?>
                </p>

            <?php else: ?>

                <div class="error">

                    No fue posible consultar el producto.

                    <?php if ($respuestaDetalleProducto !== null): ?>

                        <?= escapar(
                            mensajeError($respuestaDetalleProducto)
                        ) ?>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </section>

    <?php endif; ?>


    <!-- ==================================
         CARRITO
    =================================== -->

    <section id="carrito">

        <h2>Carrito de compras</h2>

        <?php if (!empty($productosCarrito)): ?>

            <table>

                <thead>

                    <tr>
                        <th>Producto</th>
                        <th>Precio</th>
                        <th>Cantidad</th>
                        <th>Subtotal estimado</th>
                        <th>Accion</th>
                    </tr>

                </thead>

                <tbody>

                    <?php foreach ($productosCarrito as $item): ?>

                        <?php

                        $p = $item['producto'];
                        $cantidad = (int)$item['cantidad'];

                        $precio = (float)($p['price'] ?? 0);

                        ?>

                        <tr>

                            <td>
                                <?= escapar($p['name'] ?? 'Producto') ?>
                            </td>

                            <td>
                                ₡<?= number_format($precio, 2) ?>
                            </td>

                            <td>
                                <?= $cantidad ?>
                            </td>

                            <td>
                                ₡<?= number_format($precio * $cantidad, 2) ?>
                            </td>

                            <td>

                                <form method="POST" action="index.php#carrito">

                                    <input
                                        type="hidden"
                                        name="csrf"
                                        value="<?= escapar($_SESSION['csrf']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="accion"
                                        value="eliminar"
                                    >

                                    <input
                                        type="hidden"
                                        name="producto_id"
                                        value="<?= (int)$p['id'] ?>"
                                    >

                                    <button type="submit">
                                        Eliminar
                                    </button>

                                </form>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

            <p>
                Los precios mostrados son estimados.
                El total definitivo lo calcula Aula API Lab.
            </p>

            <form method="POST" action="index.php#carrito">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= escapar($_SESSION['csrf']) ?>"
                >

                <input
                    type="hidden"
                    name="accion"
                    value="vaciar"
                >

                <button type="submit">
                    Vaciar carrito
                </button>

            </form>

            <h3>Registrar pedido</h3>

            <form method="POST" action="index.php#carrito">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?= escapar($_SESSION['csrf']) ?>"
                >

                <input
                    type="hidden"
                    name="accion"
                    value="crear_pedido"
                >

                <label>Nombre del cliente:</label>

                <input
                    type="text"
                    name="customer_name"
                    minlength="2"
                    maxlength="100"
                    required
                    placeholder="Nombre completo"
                >

                <label>Notas del pedido:</label>

                <textarea
                    name="notes"
                    maxlength="500"
                    rows="4"
                    placeholder="Notas adicionales (opcional)"
                ></textarea>

                <button type="submit">
                    Crear pedido
                </button>

            </form>

        <?php else: ?>

            <p>Tu carrito se encuentra vacio.</p>

        <?php endif; ?>

    </section>


    <!-- ==================================
         HISTORIAL DE PEDIDOS
    =================================== -->

    <section id="pedidos">

        <h2>Historial de pedidos</h2>

        <?php if (apiCorrecta($respuestaPedidos)): ?>

            <?php if (!empty($pedidos)): ?>

                <table>

                    <thead>

                        <tr>
                            <th>ID</th>
                            <th>Cliente</th>
                            <th>Estado</th>
                            <th>Total</th>
                            <th>Fecha</th>
                            <th>Accion</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($pedidos as $pedido): ?>

                            <tr>

                                <td>
                                    <?= escapar($pedido['id'] ?? '') ?>
                                </td>

                                <td>
                                    <?= escapar(
                                        $pedido['customer_name'] ?? 'N/D'
                                    ) ?>
                                </td>

                                <td>
                                    <?= escapar($pedido['status'] ?? 'N/D') ?>
                                </td>

                                <td>
                                    ₡<?= number_format(
                                        (float)($pedido['total'] ?? 0),
                                        2
                                    ) ?>
                                </td>

                                <td>
                                    <?= escapar(
                                        $pedido['created_at'] ?? 'N/D'
                                    ) ?>
                                </td>

                                <td>

                                    <a
                                        class="enlace"
                                        href="index.php?pedido=<?= (int)$pedido['id'] ?>#detalle-pedido"
                                    >
                                        Ver detalle
                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            <?php else: ?>

                <p>No hay pedidos registrados.</p>

            <?php endif; ?>

            <!-- PAGINACION DE PEDIDOS -->

            <?php

            $totalPaginasPedidos = max(
                1,
                (int)($metaPedidos['total_pages'] ?? 1)
            );

            ?>

            <?php if ($totalPaginasPedidos > 1): ?>

                <div class="paginacion">

                    <?php for (
                        $i = 1;
                        $i <= $totalPaginasPedidos;
                        $i++
                    ): ?>

                        <a href="index.php?opage=<?= $i ?>#pedidos">
                            <?= $i ?>
                        </a>

                    <?php endfor; ?>

                </div>

            <?php endif; ?>

        <?php else: ?>

            <div class="error">

                No fue posible consultar los pedidos.

                <?= escapar(mensajeError($respuestaPedidos)) ?>

            </div>

        <?php endif; ?>

    </section>


    <!-- ==================================
         DETALLE DE PEDIDO
    =================================== -->

    <?php if ($idPedido && $idPedido > 0): ?>

        <section id="detalle-pedido">

            <h2>Detalle del pedido #<?= (int)$idPedido ?></h2>

            <?php if (
                $respuestaDetallePedido !== null &&
                apiCorrecta($respuestaDetallePedido)
            ): ?>

                <?php

                $detallePedido = obtenerDatos(
                    $respuestaDetallePedido
                );

                if (isset($detallePedido['order'])) {
                    $detallePedido = $detallePedido['order'];
                }

                $lineas = $detallePedido['items']
                    ?? $detallePedido['lines']
                    ?? [];

                ?>

                <p>
                    Cliente:
                    <?= escapar(
                        $detallePedido['customer_name'] ?? 'N/D'
                    ) ?>
                </p>

                <p>
                    Estado:
                    <?= escapar($detallePedido['status'] ?? 'N/D') ?>
                </p>

                <p>
                    Total:
                    ₡<?= number_format(
                        (float)($detallePedido['total'] ?? 0),
                        2
                    ) ?>
                </p>

                <h3>Productos del pedido</h3>

                <?php if (is_array($lineas) && !empty($lineas)): ?>

                    <table>

                        <thead>

                            <tr>
                                <th>Producto</th>
                                <th>Cantidad</th>
                                <th>Precio unitario</th>
                                <th>Total de linea</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($lineas as $linea): ?>

                                <?php

                                $productoLinea = $linea['product'] ?? [];

                                $nombreProducto =
                                    $productoLinea['name']
                                    ?? $linea['product_name']
                                    ?? $linea['name']
                                    ?? 'Producto';

                                $precioUnitario =
                                    $linea['unit_price']
                                    ?? $linea['price']
                                    ?? 0;

                                $totalLinea =
                                    $linea['line_total']
                                    ?? $linea['subtotal']
                                    ?? ((float)$precioUnitario *
                                        (int)($linea['quantity'] ?? 0));

                                ?>

                                <tr>

                                    <td>
                                        <?= escapar($nombreProducto) ?>
                                    </td>

                                    <td>
                                        <?= escapar(
                                            $linea['quantity'] ?? 0
                                        ) ?>
                                    </td>

                                    <td>
                                        ₡<?= number_format(
                                            (float)$precioUnitario,
                                            2
                                        ) ?>
                                    </td>

                                    <td>
                                        ₡<?= number_format(
                                            (float)$totalLinea,
                                            2
                                        ) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php else: ?>

                    <p>No hay detalles de productos disponibles.</p>

                <?php endif; ?>

            <?php else: ?>

                <div class="error">

                    No fue posible consultar el detalle del pedido.

                    <?php if ($respuestaDetallePedido !== null): ?>

                        <?= escapar(
                            mensajeError($respuestaDetallePedido)
                        ) ?>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </section>

    <?php endif; ?>

</main>

<footer>

    <p>AulaStore - Aplicacion web desarrollada con PHP.</p>

    <p>Integracion con Aula API Lab mediante API REST y JSON.</p>

</footer>

</body>
</html>