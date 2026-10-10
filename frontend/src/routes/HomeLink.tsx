import { Link } from 'react-router-dom';
import { buttonBase, buttonVariants } from '@/components/ui/styles';
import { cn } from '@/lib/cn';
import { TAP_MIN_CLASSES } from '@/lib/tokens';

export function HomeLink() {
  return (
    <Link to="/" className={cn(TAP_MIN_CLASSES, buttonBase, buttonVariants.primary)}>
      Voltar ao início
    </Link>
  );
}
