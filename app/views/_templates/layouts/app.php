<!DOCTYPE html>

<!-- =========================================================
* Sneat - Bootstrap 5 HTML Admin Template - Pro | v1.0.0
==============================================================

* Product Page: https://themeselection.com/products/sneat-bootstrap-html-admin-template/
* Created by: ThemeSelection
* License: You must have a valid license purchased in order to legally use the theme for your project.
* Copyright ThemeSelection (https://themeselection.com)

=========================================================
 -->
<!-- beautify ignore:start -->
<html
  lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../assets/"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />

    <title>{{ APP_NAME }} | {{ pageTitle() }} </title>

    <base href="{{ BASE_URL }}">
    <meta name="base_url" content="{{ BASE_URL }}" />
    <meta name="route.modal.content" content="{{ route('modal.content') }}" />
    <meta name="description" content="" />
    <meta name="secure_token" content="{{ csrf()->getToken() ?: csrf()->init() }}" />
    <meta name="csrf-token" content="{{ csrf()->getToken() ?: csrf()->init() }}" />

    <link rel="icon" type="image/x-icon" href="{{ asset('sneat/img/favicon/favicon.ico') }}" />

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
      rel="stylesheet"
    />

    <link rel="stylesheet" href="{{ asset('sneat/assets/fonts/boxicons.css') }}" />
    <link rel="stylesheet" href="{{ asset('sneat/assets/css/core.css') }}" class="template-customizer-core-css" />
    <link rel="stylesheet" href="{{ asset('sneat/assets/css/theme-semi-dark.css') }}" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="{{ asset('sneat/css/demo.css') }}" />
    <link rel="stylesheet" href="{{ asset('sneat/assets/libs/perfect-scrollbar/perfect-scrollbar.css') }}" />

    <script src="{{ asset('sneat/assets/js/helpers.js') }}"></script>
    <script src="{{ asset('sneat/js/config.js') }}"></script>
    <script src="{{ asset('sneat/assets/libs/jquery/jquery.js') }}"></script>

    <link rel="stylesheet" href="{{ asset('general/css/toastr.min.css') }}">
    <link rel="stylesheet" href="{{ asset('general/css/skeleton.css') }}">

    <script src="{{ asset('general/js/axios.min.js') }}"></script>
    <script src="{{ asset('general/js/jquery.min.js') }}"></script>
    <script src="{{ asset('general/js/helper.js') }}"></script>
    <script src="{{ asset('general/js/toastr.min.js') }}"></script>
    <script src="{{ asset('general/js/block-ui.js') }}"></script>
    <script src="{{ asset('general/js/validation.js') }}"></script>
    <script src="{{ asset('general/js/classes/BootstrapDataTable.js') }}"></script>
    <script src="{{ asset('general/js/classes/ModalManager.js') }}"></script>


    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.12.1/css/dataTables.bootstrap5.min.css">

    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.2.9/js/dataTables.responsive.min.js"></script>

    <style>
        .swal2-customCss {
            z-index: 20000;
        }
    </style>

    @stack('styles')
  </head>

  <body>
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        @include('_templates.menu')

        <div class="layout-page">
          <nav
            class="layout-navbar container-fluid navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme"
            id="layout-navbar"
          >
            <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none">
              <a class="nav-item nav-link px-0 me-xl-4" href="javascript:void(0)">
                <i class="bx bx-menu bx-sm"></i>
              </a>
            </div>

            <div class="navbar-nav-right d-flex align-items-center" id="navbar-collapse">
              <div class="navbar-nav align-items-center">
                <div class="nav-item d-flex align-items-center">
                  <span id="currentTime" class="text-muted fw-light"></span>
                </div>
              </div>

              <ul class="navbar-nav flex-row align-items-center ms-auto">
                <li class="nav-item navbar-dropdown dropdown-user dropdown">
                  <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);" data-bs-toggle="dropdown">
                    <div class="avatar avatar-online">
                      <img src="{{ asset(currentUserAvatar(), false) }}" alt class="w-px-40 h-auto rounded-circle" />
                    </div>
                  </a>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                      <a class="dropdown-item" href="javascript:void(0);">
                        <div class="d-flex">
                          <div class="flex-shrink-0 me-3">
                            <div class="avatar avatar-online">
                              <img src="{{ asset(currentUserAvatar(), false) }}" alt class="w-px-40 h-auto rounded-circle" />
                            </div>
                          </div>
                          <div class="flex-grow-1">
                            <span class="fw-semibold d-block"> {{ currentUserFullname() }} </span>
                            <small class="text-muted">{{ currentUserRoleName() }}</small>
                          </div>
                        </div>
                      </a>
                    </li>
                    <li>
                      <div class="dropdown-divider"></div>
                    </li>
                    <li>
                      <a class="dropdown-item" href="javascript:void(0)" onclick="signOut()">
                        <i class="bx bx-power-off me-2"></i>
                        <span class="align-middle">Log Out</span>
                      </a>
                    </li>
                  </ul>
                </li>
              </ul>
            </div>
          </nav>

          <div class="content-wrapper">
            @yield('content')

            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>

      <div class="layout-overlay layout-menu-toggle"></div>
    </div>

    <script src="{{ asset('sneat/assets/libs/popper/popper.js') }}"></script>
    <script src="{{ asset('sneat/assets/js/bootstrap.js') }}"></script>
    <script src="{{ asset('sneat/assets/libs/perfect-scrollbar/perfect-scrollbar.js') }}"></script>
    <script src="{{ asset('sneat/assets/js/menu.js') }}"></script>
    <script src="{{ asset('sneat/js/main.js') }}"></script>

    <script type="text/javascript">
        $(document).ready(function() {
            clock();
        });

        function clock() {
            let today = new Date().toLocaleDateString('en-GB', {
                month: '2-digit',
                day: '2-digit',
                year: 'numeric'
            });

            $("#currentTime").html(getClock('12', 'en', true) + ' | ' + today);
            setTimeout(clock, 1000);
        }

        async function signOut() {
            if (confirm("Are you sure you want to logout?")) {
                const res = await callApi('post', '{{ route("auth.logout") }}', {});

                const resCode = parseInt(res.data.code);
                noti(resCode, res.data.message);

                if (isSuccess(resCode)) {
                    setTimeout(function() {
                        window.location.href = res.data.redirectUrl;
                    }, 650);
                }
            }
        }

        async function updateCropperPhoto(
            modalTitle = null,
            id = null,
            entityId = null,
            entityFileType = null,
            entityType = null,
            currentImage = null,
            reloadFunction = null,
            folderGroup = 'unknown',
            folderType = 'unknown',
            cropperConfig = {}
        ) {
            const data = {
                'id': id,
                'entity_id': entityId,
                'entity_type': entityType,
                'entity_file_type': entityFileType,
                'url': '{{ route("uploads.image-cropper") }}',
                'imagePath': currentImage,
                'reloadFunction': reloadFunction,
                'folder_group': folderGroup,
                'folder_type': folderType,
                'cropperConfig': cropperConfig
            };

          modalManager().loadFileContent({
            fileName: 'views/_templates/_uploadImageCropperModal.php',
            idToLoad: 'generalContent',
            sizeModal: '480px',
            title: modalTitle,
            dataArray: data,
            typeModal: 'offcanvas'
          });
        }
    </script>

    @stack('scripts')

    @include('_templates._modalGeneral')
  </body>
</html>