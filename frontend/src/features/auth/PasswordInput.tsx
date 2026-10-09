import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import { IconButton, Input } from '@/components/ui';
import type { FieldControlProps, InputProps } from '@/components/ui';

export type PasswordInputProps = Omit<InputProps, 'type'> & Partial<FieldControlProps>;

/** Campo de senha com mostrar/ocultar (o botão anuncia o estado por aria-pressed). */
export function PasswordInput(props: PasswordInputProps) {
  const [visible, setVisible] = useState(false);
  return (
    <div className="flex items-center gap-2">
      <Input {...props} type={visible ? 'text' : 'password'} />
      <IconButton
        label={visible ? 'Ocultar senha' : 'Mostrar senha'}
        aria-pressed={visible}
        variant="secondary"
        icon={visible ? <EyeOff /> : <Eye />}
        onClick={() => setVisible((v) => !v)}
      />
    </div>
  );
}
