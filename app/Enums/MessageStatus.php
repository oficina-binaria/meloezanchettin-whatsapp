<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Accepted = 'accepted';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';

    /**
     * Get the label shown to users.
     */
    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Aceita pela Meta',
            self::Sent => 'Enviada',
            self::Delivered => 'Entregue',
            self::Read => 'Lida',
            self::Failed => 'Falhou',
        };
    }

    /**
     * Determine whether this status may replace the given current status.
     *
     * Delivery notifications can arrive out of order, so a message never moves backwards.
     */
    public function canReplace(?self $current): bool
    {
        if ($current === null || $this === self::Failed) {
            return true;
        }

        return $current !== self::Failed && $this->progress() > $current->progress();
    }

    /**
     * Get the position of this status in the delivery lifecycle.
     */
    private function progress(): int
    {
        return match ($this) {
            self::Accepted => 0,
            self::Sent => 1,
            self::Delivered => 2,
            self::Read => 3,
            self::Failed => 4,
        };
    }
}
