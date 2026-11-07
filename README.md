# AulaStore

Aplicación web desarrollada en PHP que consume los servicios
de Aula API Lab mediante una API REST.

## Tecnologías utilizadas

- PHP
- HTML5
- CSS3
- cURL
- JSON
- API REST
- XAMPP

## Requisitos

- XAMPP instalado.
- PHP con extensión cURL habilitada.
- Una cuenta registrada en Aula API Lab.
- API Key válida.

## Instalación

1. Clonar o descargar el repositorio.
2. Colocar el proyecto dentro de htdocs.
3. Crear el archivo config.php.
4. Configurar la URL de la API y la API Key.
5. Iniciar Apache desde XAMPP.
6. Abrir el proyecto desde el navegador.

## Conexión con la API

URL base:

https://darkred-rook-511555.hostingersite.com/api/v1

La aplicación utiliza el encabezado X-API-Key
para autenticarse con la API.

## Endpoints utilizados

| Método | Endpoint | Función |
|--------|----------|---------|
| GET | health.php | Comprobar conexión |
| GET | products.php | Consultar productos |
| GET | product.php | Consultar producto individual |
| POST | orders.php | Crear pedidos |
| GET | orders.php | Consultar pedidos |
| GET | order.php | Consultar detalle de pedido |

## Funcionalidades

- Comprobación de conexión con la API.
- Consulta de productos.
- Búsqueda y filtros.
- Paginación.
- Consulta de detalles de productos.
- Carrito de compras.
- Creación de pedidos.
- Consulta del historial.
- Consulta del detalle de pedidos.
- Validación de información.
- Manejo de errores HTTP y JSON.

## Seguridad

La API Key se almacena en config.php.

Este archivo está excluido de GitHub mediante
.gitignore.

Todas las solicitudes a Aula API Lab se realizan
desde el backend utilizando PHP y cURL.

El navegador no tiene acceso a la API Key.

## Autor

Proyecto académico de integración con Aula API Lab.