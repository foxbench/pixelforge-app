<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Welcome" />
    <div class="flex min-h-screen flex-col items-center justify-center gap-6 p-6 text-center">
        <h1 class="text-3xl font-semibold tracking-tight">Pixel Studio</h1>
        <p class="max-w-md text-muted-foreground">
            Generate images with Pixelforge. See the live status of every generation, an estimate before you submit, and the exact cost afterwards.
            You pay Pixelforge's cost plus 25%, and failed generations are free.
        </p>
        <div class="flex gap-3">
            <template v-if="$page.props.auth.user">
                <Button as-child><Link :href="route('generations.create')">Open the app</Link></Button>
            </template>
            <template v-else>
                <Button as-child><Link :href="route('register')">Register ($2 free credit)</Link></Button>
                <Button as-child variant="outline"><Link :href="route('login')">Log in</Link></Button>
            </template>
        </div>
    </div>
</template>
