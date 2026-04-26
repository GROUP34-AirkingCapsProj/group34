-- AirKing PWA inventory (Supabase). Run in Supabase SQL Editor or via CLI.
-- Requires: extensions pgcrypto (gen_random_uuid) — enabled by default on Supabase.

create extension if not exists "pgcrypto";

-- Products (barcode-unique; scan-to-create inserts here)
create table if not exists public.products (
  id uuid primary key default gen_random_uuid(),
  name text not null,
  barcode text not null unique,
  quantity integer not null default 0 check (quantity >= 0),
  price numeric(12, 2) not null default 0 check (price >= 0),
  category text not null default 'Uncategorized',
  created_at timestamptz not null default now(),
  updated_at timestamptz not null default now()
);

create index if not exists products_barcode_idx on public.products (barcode);
create index if not exists products_category_idx on public.products (category);
create index if not exists products_created_at_idx on public.products (created_at desc);

create or replace function public.set_updated_at()
returns trigger
language plpgsql
as $$
begin
  new.updated_at = now();
  return new;
end;
$$;

drop trigger if exists products_set_updated_at on public.products;
create trigger products_set_updated_at
  before update on public.products
  for each row execute function public.set_updated_at();

-- Activity: who scanned what (optional analytics)
create table if not exists public.scan_events (
  id uuid primary key default gen_random_uuid(),
  barcode text not null,
  product_id uuid references public.products (id) on delete set null,
  action text not null check (action in ('found', 'created')),
  user_id uuid references auth.users (id) on delete set null,
  created_at timestamptz not null default now()
);

create index if not exists scan_events_created_at_idx on public.scan_events (created_at desc);

-- Role scaffold (extend RLS below when you use profiles.role)
create table if not exists public.profiles (
  user_id uuid primary key references auth.users (id) on delete cascade,
  role text not null default 'staff' check (role in ('admin', 'staff')),
  full_name text,
  created_at timestamptz not null default now()
);

create or replace function public.handle_new_user()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
  insert into public.profiles (user_id, role, full_name)
  values (new.id, 'staff', coalesce(new.raw_user_meta_data->>'full_name', split_part(new.email, '@', 1)))
  on conflict (user_id) do nothing;
  return new;
end;
$$;

drop trigger if exists on_auth_user_created on auth.users;
create trigger on_auth_user_created
  after insert on auth.users
  for each row execute function public.handle_new_user();

alter table public.products enable row level security;
alter table public.scan_events enable row level security;
alter table public.profiles enable row level security;

-- Authenticated users: full CRUD on products and scan_events (adjust for staff read-only if needed)
drop policy if exists "products_authenticated_all" on public.products;
create policy "products_authenticated_all" on public.products
  for all to authenticated using (true) with check (true);

drop policy if exists "scan_events_authenticated_all" on public.scan_events;
drop policy if exists "scan_events_select" on public.scan_events;
drop policy if exists "scan_events_insert_own" on public.scan_events;

create policy "scan_events_select" on public.scan_events
  for select to authenticated using (true);

create policy "scan_events_insert_own" on public.scan_events
  for insert to authenticated with check (auth.uid() = user_id);

-- Profiles: users see/update own row only
drop policy if exists "profiles_select_own" on public.profiles;
create policy "profiles_select_own" on public.profiles
  for select to authenticated using (auth.uid() = user_id);

drop policy if exists "profiles_update_own" on public.profiles;
create policy "profiles_update_own" on public.profiles
  for update to authenticated using (auth.uid() = user_id) with check (auth.uid() = user_id);

-- Optional: enable Realtime for these tables in Supabase Dashboard → Database → Replication,
-- or run once (fails if already a member):
-- alter publication supabase_realtime add table public.products;
-- alter publication supabase_realtime add table public.scan_events;
