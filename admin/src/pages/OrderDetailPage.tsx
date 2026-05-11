import { format } from 'date-fns'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { Helmet } from 'react-helmet-async'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { Breadcrumbs } from '@/components/Breadcrumbs'
import { PageHeader } from '@/components/PageHeader'
import { OrderStatusBadge } from '@/components/StatusBadge'
import { Button } from '@/components/ui/Button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { useOrderQuery } from '@/hooks/useOrderQuery'
import { normalizeAxiosError } from '@/api/errors'
import { runOrderRepush, saveOrderDetails } from '@/services/ordersService'
import { toastError, toastSuccess } from '@/store/toastStore'
import type { OrderUpdateRequest } from '@/types/api'

export default function OrderDetailPage() {
  const { id } = useParams()
  const oid = Number.parseInt(id ?? '', 10)
  const { t } = useTranslation('common')
  const { t: tn } = useTranslation('nav')
  const { t: to } = useTranslation('orderDetail')
  const navigate = useNavigate()
  const qc = useQueryClient()
  const q = useOrderQuery(Number.isFinite(oid) ? oid : undefined)
  const [form, setForm] = useState<OrderUpdateRequest>({
    customer_email: '',
    customer_name: '',
    total_paid: 0,
    total_products: 0,
    total_shipping: 0,
    discount_total: 0,
    currency: 'EUR',
    shipping_country_iso: '',
    billing_country_iso: '',
    shipping_method: '',
    payment_method: '',
    shipping_address_json: '',
    billing_address_json: '',
    ay_status: '',
    sync_status: 'pending',
    error_message: '',
  })
  const repush = useMutation({
    mutationFn: () => runOrderRepush(oid, true),
    onSuccess: async (res) => {
      toastSuccess(to('repushSuccessTitle'), res.message ?? to('repushSuccessDescription'))
      await q.refetch()
      await qc.invalidateQueries({ predicate: (query) => query.queryKey[0] === 'orders' })
    },
    onError: (err) => {
      const detail = normalizeAxiosError(err)
      toastError(to('repushErrorTitle'), `${to('repushErrorDescription')}: ${detail}`)
    },
  })

  const order = q.data?.order
  const items = q.data?.items ?? []

  useEffect(() => {
    if (!order) return
    setForm({
      customer_email: order.customer_email ?? '',
      customer_name: order.customer_name ?? '',
      total_paid: Number(order.total_paid ?? 0),
      total_products: Number(order.total_products ?? 0),
      total_shipping: Number(order.total_shipping ?? 0),
      discount_total: Number(order.discount_total ?? 0),
      currency: order.currency ?? 'EUR',
      shipping_country_iso: order.shipping_country_iso ?? '',
      billing_country_iso: order.billing_country_iso ?? '',
      shipping_method: order.shipping_method ?? '',
      payment_method: order.payment_method ?? '',
      shipping_address_json: order.shipping_address_json ?? '',
      billing_address_json: order.billing_address_json ?? '',
      ay_status: order.ay_status ?? '',
      sync_status: order.sync_status,
      error_message: order.error_message ?? '',
    })
  }, [order])

  const saveDetails = useMutation({
    mutationFn: () => runSave(oid, form),
    onSuccess: async (res) => {
      setForm((prev) => ({
        ...prev,
        ay_status: res.order.ay_status ?? '',
        sync_status: res.order.sync_status,
        total_paid: Number(res.order.total_paid ?? 0),
      }))
      toastSuccess(to('editSavedTitle'), to('editSavedDescription'))
      await q.refetch()
      await qc.invalidateQueries({ predicate: (query) => query.queryKey[0] === 'orders' })
    },
    onError: (err) => toastError(to('editSaveFailedTitle'), normalizeAxiosError(err)),
  })

  if (q.isError) {
    return (
      <div className="p-6">
        <p className="text-sm text-red-600">{normalizeAxiosError(q.error)}</p>
        <Button className="mt-4" variant="secondary" type="button" onClick={() => navigate('/orders')}>
          {to('backToOrders')}
        </Button>
      </div>
    )
  }

  if (!order && q.isLoading) {
    return (
      <div className="space-y-4 p-4">
        <div className="h-8 w-56 animate-pulse rounded bg-zinc-200 dark:bg-zinc-800" />
        <div className="h-32 animate-pulse rounded-xl bg-zinc-200 dark:bg-zinc-800" />
      </div>
    )
  }

  if (!order) return null

  const steps = [
    { label: to('recordCreated'), at: order.created_at, ok: true },
    {
      label: to('syncLabel', { status: order.sync_status }),
      at: order.created_at,
      ok: order.sync_status !== 'failed' && order.sync_status !== 'error',
    },
    { label: to('ayStatusLabel', { status: order.ay_status ?? 'n/a' }), at: order.created_at, ok: true },
  ]

  return (
    <div>
      <Helmet>
        <title>
          {to('orderTitle', { id: order.ay_order_id })} — {t('appName')}
        </title>
      </Helmet>
      <Breadcrumbs items={[{ label: tn('orders'), to: '/orders' }, { label: order.ay_order_id }]} />
      <PageHeader
        title={to('orderTitle', { id: order.ay_order_id })}
        description={`PS #${order.ps_order_id ?? '—'} · ${order.total_paid.toFixed(2)} ${to('paid')}`}
        actions={
          <div className="flex flex-wrap gap-2">
            <Button
              variant="secondary"
              type="button"
              onClick={() => repush.mutate()}
              disabled={repush.isPending || !Number.isFinite(oid)}
            >
              {repush.isPending ? to('repushing') : to('repushToPrestashop')}
            </Button>
            <Button variant="secondary" type="button" onClick={() => q.refetch()} disabled={q.isFetching}>
              {t('refresh')}
            </Button>
          </div>
        }
      />

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>{to('statusTimeline')}</CardTitle>
          </CardHeader>
          <CardContent>
            <ol className="space-y-4 border-s border-[var(--color-border)] ps-4">
              {steps.map((s, i) => (
                <li key={i} className="relative ps-4">
                  <span
                    className={`absolute start-0 top-1.5 flex h-2.5 w-2.5 -translate-x-[calc(50%+2px)] rounded-full ${
                      s.ok ? 'bg-emerald-500' : 'bg-red-500'
                    }`}
                  />
                  <p className="text-sm font-medium">{s.label}</p>
                  <p className="text-xs text-[var(--color-muted)]">
                    {s.at ? format(new Date(s.at), 'PPpp') : '—'}
                  </p>
                </li>
              ))}
            </ol>
            <p className="text-xs text-[var(--color-muted)]">
              {to('timelineHint')}
            </p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>{to('summary')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2 text-sm">
            <p>
              <span className="text-[var(--color-muted)]">{to('sync')}</span>{' '}
              <OrderStatusBadge status={order.sync_status} />
            </p>
            <p>
              <span className="text-[var(--color-muted)]">{to('ayStatus')}</span> {order.ay_status ?? '—'}
            </p>
          </CardContent>
        </Card>
      </div>

      <Card className="mt-6">
        <CardHeader>
          <CardTitle>{to('editOrder')}</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid gap-3 md:grid-cols-2">
            <Input
              value={form.customer_email ?? ''}
              onChange={(e) => setForm((s) => ({ ...s, customer_email: e.target.value }))}
              placeholder={to('customerEmail')}
            />
            <Input
              value={form.customer_name ?? ''}
              onChange={(e) => setForm((s) => ({ ...s, customer_name: e.target.value }))}
              placeholder={to('customerName')}
            />
            <Input
              type="number"
              value={String(form.total_paid ?? 0)}
              onChange={(e) => setForm((s) => ({ ...s, total_paid: Number(e.target.value || 0) }))}
              placeholder={to('totalPaid')}
            />
            <Input
              type="number"
              value={String(form.total_shipping ?? 0)}
              onChange={(e) => setForm((s) => ({ ...s, total_shipping: Number(e.target.value || 0) }))}
              placeholder={to('totalShipping')}
            />
            <Input
              type="number"
              value={String(form.total_products ?? 0)}
              onChange={(e) => setForm((s) => ({ ...s, total_products: Number(e.target.value || 0) }))}
              placeholder={to('totalProducts')}
            />
            <Input
              type="number"
              value={String(form.discount_total ?? 0)}
              onChange={(e) => setForm((s) => ({ ...s, discount_total: Number(e.target.value || 0) }))}
              placeholder={to('discountTotal')}
            />
            <Input
              value={form.currency ?? 'EUR'}
              onChange={(e) => setForm((s) => ({ ...s, currency: e.target.value.toUpperCase() }))}
              placeholder={to('currency')}
            />
            <Input
              value={form.ay_status ?? ''}
              onChange={(e) => setForm((s) => ({ ...s, ay_status: e.target.value }))}
              placeholder={to('ayStatus')}
            />
            <select
              className="w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] px-3 py-2 text-sm"
              value={form.sync_status ?? 'pending'}
              onChange={(e) => setForm((s) => ({ ...s, sync_status: e.target.value }))}
            >
              {['pending', 'importing', 'imported', 'status_pushed', 'error', 'quarantined'].map((status) => (
                <option key={status} value={status}>
                  {status}
                </option>
              ))}
            </select>
            <Input
              value={form.shipping_method ?? ''}
              onChange={(e) => setForm((s) => ({ ...s, shipping_method: e.target.value }))}
              placeholder={to('shippingMethod')}
            />
            <Input
              value={form.payment_method ?? ''}
              onChange={(e) => setForm((s) => ({ ...s, payment_method: e.target.value }))}
              placeholder={to('paymentMethod')}
            />
            <Input
              value={form.shipping_country_iso ?? ''}
              onChange={(e) => setForm((s) => ({ ...s, shipping_country_iso: e.target.value.toUpperCase() }))}
              placeholder={to('shippingCountry')}
            />
            <Input
              value={form.billing_country_iso ?? ''}
              onChange={(e) => setForm((s) => ({ ...s, billing_country_iso: e.target.value.toUpperCase() }))}
              placeholder={to('billingCountry')}
            />
          </div>
          <textarea
            className="mt-3 min-h-[80px] w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30"
            value={form.shipping_address_json ?? ''}
            onChange={(e) => setForm((s) => ({ ...s, shipping_address_json: e.target.value }))}
            placeholder={to('shippingAddressJson')}
          />
          <textarea
            className="mt-3 min-h-[80px] w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30"
            value={form.billing_address_json ?? ''}
            onChange={(e) => setForm((s) => ({ ...s, billing_address_json: e.target.value }))}
            placeholder={to('billingAddressJson')}
          />
          <textarea
            className="mt-3 min-h-[64px] w-full rounded-lg border border-[var(--color-border)] bg-[var(--color-surface-elevated)] px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30"
            value={form.error_message ?? ''}
            onChange={(e) => setForm((s) => ({ ...s, error_message: e.target.value }))}
            placeholder={to('errorMessage')}
          />
          <div className="mt-3 flex justify-end">
            <Button type="button" onClick={() => saveDetails.mutate()} disabled={saveDetails.isPending}>
              {saveDetails.isPending ? to('saving') : to('saveChanges')}
            </Button>
          </div>
        </CardContent>
      </Card>

      <Card className="mt-6">
        <CardHeader>
          <CardTitle>{to('items')}</CardTitle>
        </CardHeader>
        <CardContent className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead className="text-left text-xs uppercase text-[var(--color-muted)]">
              <tr>
                <th className="py-2 pr-4">{t('sku')}</th>
                <th className="py-2 pr-4">{to('qty')}</th>
                <th className="py-2 pr-4">{to('unit')}</th>
                <th className="py-2">{t('status')}</th>
              </tr>
            </thead>
            <tbody>
              {items.length === 0 ? (
                <tr>
                  <td colSpan={4} className="py-8 text-center text-[var(--color-muted)]">
                    {t('noData')}
                  </td>
                </tr>
              ) : (
                items.map((it) => (
                  <tr key={it.id} className="border-t border-[var(--color-border)]">
                    <td className="py-2 pr-4 font-mono text-xs">{it.sku ?? '—'}</td>
                    <td className="py-2 pr-4">{it.quantity}</td>
                    <td className="py-2 pr-4 tabular-nums">{it.unit_price.toFixed(2)}</td>
                    <td className="py-2 text-xs">{it.item_status ?? '—'}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </CardContent>
      </Card>

      <Card className="mt-6">
        <CardHeader>
          <CardTitle>{to('customerAddresses')}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3 text-sm">
          <div>
            <p className="text-xs text-[var(--color-muted)]">{to('shippingAddressJson')}</p>
            <pre className="mt-1 max-h-48 overflow-auto rounded-lg border border-[var(--color-border)] p-3 font-mono text-xs">
              {order.shipping_address_json || '—'}
            </pre>
          </div>
          <div>
            <p className="text-xs text-[var(--color-muted)]">{to('billingAddressJson')}</p>
            <pre className="mt-1 max-h-48 overflow-auto rounded-lg border border-[var(--color-border)] p-3 font-mono text-xs">
              {order.billing_address_json || '—'}
            </pre>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}

function runSave(orderId: number, form: OrderUpdateRequest) {
  return saveOrderDetails(orderId, {
    customer_email: form.customer_email ?? '',
    customer_name: form.customer_name ?? '',
    total_paid: Number(form.total_paid ?? 0),
    total_products: Number(form.total_products ?? 0),
    total_shipping: Number(form.total_shipping ?? 0),
    discount_total: Number(form.discount_total ?? 0),
    currency: String(form.currency ?? 'EUR').toUpperCase().slice(0, 3),
    shipping_country_iso: String(form.shipping_country_iso ?? '').toUpperCase().slice(0, 2),
    billing_country_iso: String(form.billing_country_iso ?? '').toUpperCase().slice(0, 2),
    shipping_method: form.shipping_method ?? '',
    payment_method: form.payment_method ?? '',
    shipping_address_json: form.shipping_address_json ?? '',
    billing_address_json: form.billing_address_json ?? '',
    ay_status: form.ay_status ?? '',
    sync_status: form.sync_status ?? 'pending',
    error_message: form.error_message ?? '',
  })
}
