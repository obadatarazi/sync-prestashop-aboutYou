import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { useAuthStore } from '@/store/authStore'

const schema = z.object({
  token: z.string().min(1, 'errors.tokenRequired'),
  remember: z.boolean(),
})

export type LoginForm = z.infer<typeof schema>

export function useLoginController() {
  const login = useAuthStore((s) => s.login)
  const form = useForm<LoginForm>({
    resolver: zodResolver(schema),
    defaultValues: { token: import.meta.env.VITE_API_TOKEN ?? '', remember: true },
  })

  const submit = (values: LoginForm) => {
    login(values.token.trim(), values.remember)
  }

  return { form, submit }
}
