document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('admin-sidebar');

    if (!sidebar) {
        return;
    }

    const mainContent = document.getElementById('admin-main-content');
    const backdrop = document.getElementById('admin-sidebar-backdrop');
    const mobileToggle = document.getElementById('admin-sidebar-mobile-toggle');
    const desktopToggle = document.getElementById('admin-sidebar-collapse');

    const closeMobileSidebar = () => {
        sidebar.classList.add('-translate-x-full');
        backdrop?.classList.add('hidden');
    };

    const openMobileSidebar = () => {
        sidebar.classList.remove('-translate-x-full');
        backdrop?.classList.remove('hidden');
    };

    mobileToggle?.addEventListener('click', () => {
        const isClosed = sidebar.classList.contains('-translate-x-full');

        if (isClosed) {
            openMobileSidebar();
        } else {
            closeMobileSidebar();
        }
    });

    backdrop?.addEventListener('click', closeMobileSidebar);

    const topbar = document.getElementById('admin-topbar');

    const setSidebarCollapsed = (isCollapsed) => {
        sidebar.classList.toggle('lg:w-[76px]', isCollapsed);
        sidebar.classList.toggle('lg:w-[272px]', !isCollapsed);

        mainContent?.classList.toggle('lg:ml-[76px]', isCollapsed);
        mainContent?.classList.toggle('lg:ml-[272px]', !isCollapsed);

        topbar?.classList.toggle('lg:left-[76px]', isCollapsed);
        topbar?.classList.toggle('lg:left-[272px]', !isCollapsed);

        sidebar.querySelectorAll('.admin-sidebar-label').forEach((element) => {
            element.classList.toggle('lg:hidden', isCollapsed);
        });

        sidebar.querySelectorAll('[data-nav-group-items]').forEach((items) => {
            if (isCollapsed) {
                // Simpan keadaan grup sebelum diciutkan.
                items.dataset.wasOpen = items.classList.contains('hidden') ? '0' : '1';
            } else {
                // Kembalikan keadaan grup seperti sebelum sidebar diciutkan.
                const wasOpen = items.dataset.wasOpen === '1';
                items.classList.toggle('hidden', !wasOpen);
            }
        });

        desktopToggle?.querySelector('svg')?.classList.toggle('rotate-180', isCollapsed);

        localStorage.setItem(
            'admin-sidebar-collapsed',
            isCollapsed ? '1' : '0'
        );
    };

    desktopToggle?.addEventListener('click', () => {
        const isCollapsed = !sidebar.classList.contains('lg:w-[76px]');
        setSidebarCollapsed(isCollapsed);
    });

    if (localStorage.getItem('admin-sidebar-collapsed') === '1') {
        setSidebarCollapsed(true);
    }

    document.querySelectorAll('[data-nav-group-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const section = button.closest('[data-nav-group]');
            const items = section?.querySelector('[data-nav-group-items]');
            const chevron = button.querySelector('[data-nav-chevron]');

            items?.classList.toggle('hidden');
            chevron?.classList.toggle('rotate-180');

            button.setAttribute(
                'aria-expanded',
                items?.classList.contains('hidden') ? 'false' : 'true'
            );
        });
    });

    const toggleMenu = (buttonId, menuId) => {
        const button = document.getElementById(buttonId);
        const menu = document.getElementById(menuId);

        button?.addEventListener('click', (event) => {
            event.stopPropagation();
            menu?.classList.toggle('hidden');
        });

        return menu;
    };

    const profileMenu = toggleMenu(
        'admin-profile-toggle',
        'admin-profile-menu'
    );

    document.addEventListener('click', (event) => {
        if (!event.target.closest('#admin-profile-toggle')) {
            profileMenu?.classList.add('hidden');
        }
    });
});