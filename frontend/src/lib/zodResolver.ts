import type { FieldErrors, FieldValues, Resolver } from 'react-hook-form';
import type { ZodType } from 'zod';

/**
 * Resolver do react-hook-form para um schema zod (substitui o @hookform/resolvers, que não está na
 * stack aprovada): valida os valores do form e devolve o resultado transformado ou os erros por
 * campo (primeira mensagem de cada um).
 */
export function zodResolver<TValues extends FieldValues, TOutput>(
  schema: ZodType<TOutput, TValues>,
): Resolver<TValues, unknown, TOutput> {
  return (values) => {
    const result = schema.safeParse(values);
    if (result.success) return { values: result.data, errors: {} };
    const errors: Record<string, { type: string; message: string }> = {};
    for (const issue of result.error.issues) {
      const field = issue.path.join('.');
      if (field && !errors[field]) errors[field] = { type: issue.code, message: issue.message };
    }
    return { values: {}, errors: errors as FieldErrors<TValues> };
  };
}
