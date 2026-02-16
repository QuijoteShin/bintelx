<?php # bintelx/kernel/CoroutineAware.php
namespace bX;

# Trait para estado per-request coroutine-safe
# Uso: `use CoroutineAware;` en Profile, Entity, Args, etc.
# Acceso: `MiClase::ctx()->propiedad`
trait CoroutineAware
{
    private static ?self $fpmInstance = null;

    # Retorna instancia per-coroutine (Swoole) o singleton (FPM/CLI)
    # IMPORTANTE: el context de coroutine NO se hereda a coroutines hijas (go()).
    # Cada go() tiene su propio context vacío. No usar go() en flujo de request.
    public static function ctx(): static
    {
        if (class_exists('\Swoole\Coroutine', false) && \Swoole\Coroutine::getCid() > 0) {
            $ctx = \Swoole\Coroutine::getContext();
            return $ctx[static::class] ??= new static();
        }
        return static::$fpmInstance ??= new static();
    }

    # Resetea instancia (nueva instancia limpia). Llamar al inicio y finally de cada request.
    # En Swoole el context muere con la coroutine, pero reset explícito es defensivo.
    public static function resetCtx(): void
    {
        if (class_exists('\Swoole\Coroutine', false) && \Swoole\Coroutine::getCid() > 0) {
            \Swoole\Coroutine::getContext()[static::class] = new static();
        } else {
            static::$fpmInstance = new static();
        }
    }
}
