# FT Stacking Inspector (MVP) `v0.1.0`

**Estado:** beta  
**Ámbito:** frontend • **WP:** 6.4+ (Probado 6.6)  
**Requiere:** PHP 7.4+

## Qué hace
Panel flotante para **visualizar el orden de pintura (stacking), contextos de apilamiento y DOM**, y **ajustar z-index / order** de elementos en vivo. Atajo: **Alt+Z**.

## Uso rápido
1. Copia `snippet.php` como plugin MU o en tu gestor de snippets.
2. Entra logueado (rol con `edit_posts`).
3. Pulsa **Alt+Z** para abrir/cerrar.
4. Pestañas:
   - **Stack**: orden de pintura por **stacking contexts**.
   - **DOM**: orden puro del DOM.
5. Controles por elemento:
   - `z-index` (input, z–, z+)
   - `order` (si es item Flex/Grid; o–, o+)
   - **Destacar** (resalta bounding box)
   - **Reset** (revierte cambios del elemento)
6. **Reset all** devuelve todo a su estado original.

## Notas
- Solo se inyecta para usuarios logueados con permisos de edición.
- Los cambios son temporales (sobre estilos inline) y sirven para **diagnóstico**.

## Roadmap (ideas)
- Selección directa al pasar el cursor + “fijar” selección.
- Profundidad de anidamiento plegable por contexto.
- Indicador del **pintado relativo** cuando z-index coincide.
- Exportar/Importar ajustes (JSON).

**Créditos:** Flowtitude
