import { useState } from 'react';
import { cn } from '@/lib/cn';
import { initials } from './initials';

export interface AvatarProps {
  name: string;
  /** Foto do usuário (futuro upload). Sem ela, ou se falhar ao carregar, mostra as iniciais. */
  src?: string | null;
  className?: string;
}

/**
 * Avatar circular, decorativo: quem o usa (ex.: botão do menu do usuário) dá o nome acessível.
 * Tamanho vem do pai (padrão 40px, dentro de um alvo de 48px).
 */
export function Avatar({ name, src, className }: AvatarProps) {
  const [failedSrc, setFailedSrc] = useState<string | null>(null);
  const showImage = Boolean(src) && failedSrc !== src;
  return (
    <span
      aria-hidden="true"
      data-testid="avatar"
      className={cn(
        'inline-flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-full bg-brand-subtle text-sm font-bold text-brand-pressed select-none',
        className,
      )}
    >
      {showImage ? (
        <img
          src={src ?? undefined}
          alt=""
          className="size-full object-cover"
          onError={() => setFailedSrc(src ?? null)}
        />
      ) : (
        initials(name)
      )}
    </span>
  );
}
