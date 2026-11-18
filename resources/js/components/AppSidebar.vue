<script setup lang="ts">
import MoneyText from '@/components/MoneyText.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { BarChart3, Coins, Images, Sparkles } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        { title: 'Generate', href: '/generate', icon: Sparkles },
        { title: 'History', href: '/generations', icon: Images },
        { title: 'Credits', href: '/credits', icon: Coins },
    ];

    if (page.props.auth.user.is_admin) {
        items.push({ title: 'Admin report', href: '/admin/report', icon: BarChart3 });
    }

    return items;
});
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('generations.create')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <Link :href="route('credits.index')" class="rounded-md bg-sidebar-accent px-3 py-2 text-sm group-data-[collapsible=icon]:hidden">
                <span class="text-muted-foreground">Balance</span>
                <span class="float-right font-medium"><MoneyText :money="page.props.auth.balance" /></span>
            </Link>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
