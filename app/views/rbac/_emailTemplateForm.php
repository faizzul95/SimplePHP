<!-- Summernote CSS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.9.1/summernote-bs5.min.css" crossorigin="anonymous" />

<style>
    .ribbon-box {
        position: relative;
    }

    .ribbon {
        position: absolute;
        top: -5px;
        left: -5px;
        z-index: 1;
    }

    .ribbon-primary {
        background: #007bff;
        color: white;
        padding: 5px 15px;
        border-radius: 3px;
    }

    .fill {
        min-height: 600px;
    }

    .border-right {
        border-right: 1px solid #dee2e6;
    }

    #previewDiv {
        border: 1px solid #e9ecef;
        padding: 15px;
        min-height: 400px;
        background: #f8f9fa;
        border-radius: 5px;
    }

    .no-data {
        text-align: center;
        color: #6c757d;
        font-style: italic;
        padding: 50px;
    }
</style>

<div class="container-fluid mt-4">
    <div class="row">
        <!-- Preview Section -->
        <div class="col-lg-6 col-md-12 fill border-right p-4 overflow-hidden">
            <div class="card ribbon-box border shadow-none mb-lg-0" id="bodyTemplateDiv">
                <div class="card-header">
                    <span class="ribbon ribbon-primary ribbon-shape"><span> Preview </span></span>
                    <button type="button" class="btn btn-warning btn-sm float-end" onclick="refreshPreview()" title="Refresh">
                        <i class="bx bx-rotate-right"></i>
                    </button>
                </div>
                <div class="card-body">
                    <div id="previewDiv" style="display: block;">
                        <div class="no-data">No content to preview</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Section -->
        <div class="col-lg-6 col-md-12 fill p-4">
            <div class="row">
                <div class="alert alert-info" role="alert">
                    <i class="fa fa-edit label-icon"></i> Register / Update Form
                </div>

                <form id="formTemplate" action="controllers/MasterEmailTemplateController.php" method="POST">
                    <div class="row">
                        <div class="col-12 col-sm-12">
                            <label class="form-label"> Subject <span class="text-danger">*</span></label>
                            <input type="text" id="email_subject" name="email_subject" maxlength="255" class="form-control" autocomplete="off" required>
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-6 col-sm-6">
                            <label class="form-label"> CC </label>
                            <input type="email" id="email_cc" name="email_cc" maxlength="255" class="form-control" autocomplete="off">
                        </div>
                        <div class="col-6 col-sm-6">
                            <label class="form-label"> BCC </label>
                            <input type="email" id="email_bcc" name="email_bcc" maxlength="255" class="form-control" autocomplete="off">
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-lg-12">
                            <label class="form-label"> Description <span class="text-danger">*</span></label>
                            <input type="hidden" id="email_body" name="email_body" class="form-control" readonly>
                            <textarea id="templateEditor"></textarea>
                        </div>
                    </div>

                    <div class="row mt-2" style="display: none;">
                        <div class="col-12 col-sm-12">
                            <label class="form-label"> Footer </label>
                            <input type="text" id="email_footer" name="email_footer" maxlength="255" class="form-control" autocomplete="off">
                        </div>
                    </div>

                    <div class="row mt-2">
                        <div class="col-6 col-sm-6">
                            <label class="form-label"> Email Type <span class="text-danger">*</span></label>
                            <input type="text" id="email_type" name="email_type" maxlength="255" class="form-control" autocomplete="off" readonly>
                        </div>
                        <div class="col-6 col-sm-6">
                            <label class="form-label"> Status <span class="text-danger">*</span></label>
                            <select id="email_status" name="email_status" class="form-control" required>
                                <option value="1"> Active </option>
                                <option value="0"> Inactive </option>
                            </select>
                        </div>
                    </div>

                    <div class="row mt-2 mb-2">
                        <div class="col-lg-12">
                            <span class="text-danger">* Indicates a required field</span>
                            <div class="text-center mt-4">
                                <input type="hidden" id="id" name="id" placeholder="email_id" readonly>
                                <input type="hidden" name="action" value="save" readonly>
                                <input type="hidden" name="_whitelist_field" value="email_body" readonly>
                                <button id="submitBtn" type="submit" class="btn btn-md btn-info mb-3">
                                    <i class='bx bx-save'></i> Save
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Summernote JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.9.1/summernote-bs5.min.js" crossorigin="anonymous"></script>

<script>
    function initializeSummernote() {
        $('#templateEditor').summernote({
            callbacks: {
                onChange: function(contents, $editable) {
                    // Update hidden field with content
                    $('#email_body').val(contents);
                    // Auto-refresh preview when content changes
                    refreshPreview();
                },
                onPaste: function(e) {
                    // Refresh preview after paste
                    setTimeout(refreshPreview, 100);
                }
            },
            placeholder: 'Type your email content here...',
            tabsize: 2,
            height: 250,
            toolbar: [
                ['style', ['style']],
                ['font', ['bold', 'underline', 'clear']],
                ['color', ['color']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['table', ['table']],
                // ['insert', ['link', 'picture', 'video']],
                ['view', ['fullscreen', 'codeview', 'help']]
            ],
            styleTags: [
                'p',
                {
                    title: 'Blockquote',
                    tag: 'blockquote',
                    className: 'blockquote',
                    value: 'blockquote'
                },
                'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'
            ]
        });
    }

    function getPassData(baseUrl, data) {

        setTimeout(function() {
            // Initialize Summernote if not already initialized
            if (!$('#templateEditor').hasClass('note-editable')) {
                initializeSummernote();
            }

            $('#templateEditor').summernote('code', '');
        }, 80);

        if (!empty(data)) {
            // Populate form fields
            $('#email_type').val(data['email_type'] || '');
            $('#email_subject').val(data['email_subject'] || '');
            $('#email_footer').val(data['email_footer'] || '');
            $('#email_cc').val(data['email_cc'] || '');
            $('#email_bcc').val(data['email_bcc'] || '');
            $('#email_status').val(data['email_status']);
            $('#id').val(data['email_id'] || data['id'] || '');
            $('#email_body').val(data['email_body'] || '');

            setTimeout(function() {
                // Set Summernote content
                $('#templateEditor').summernote('code', data['email_body'] || '');
                // Update preview
                refreshPreview();
            }, 150);
        } else {
            // Clear form and show no data message
            clearForm();
            showNoDataMessage();
        }
    }

    function refreshPreview() {
        try {
            // Get content from Summernote editor
            let editorContent = $('#templateEditor').summernote('code');

            // Update hidden field
            $('#email_body').val(editorContent);

            // Update preview div
            if (editorContent && editorContent.trim() !== '' && editorContent !== '<p><br></p>') {
                $('#previewDiv').html(editorContent);
            } else {
                showNoDataMessage();
            }
        } catch (error) {
            console.error('Error refreshing preview:', error);
            showNoDataMessage();
        }
    }

    function showNoDataMessage() {
        $('#previewDiv').html('<div class="no-data">No content to preview</div>');
    }

    function clearForm() {
        // Clear all form fields
        $('#email_subject').val('');
        $('#email_cc').val('');
        $('#email_bcc').val('');
        $('#email_footer').val('');
        $('#email_type').val('');
        $('#email_status').val('1');
        $('#id').val('');
        $('#email_body').val('');

        // Clear Summernote editor
        if ($('#templateEditor').hasClass('note-editable')) {
            $('#templateEditor').summernote('code', '');
        }
    }

    function replacePlaceholders(text, data) {
        if (!text || !data) return text;
        return text.replace(/%([^%]+)%/g, (match, key) => {
            return data[key] || match;
        });
    }

    function insertData(dataToInsert) {
        if (dataToInsert) {
            $('#templateEditor').summernote('insertText', '%' + dataToInsert + '%');
            refreshPreview();
        }
    }

    // Form submission handler
    $("#formTemplate").submit(function(event) {
        event.preventDefault();

        // Update email_body before validation
        let editorContent = $('#templateEditor').summernote('code');
        $('#email_body').val(editorContent);

        if (validateDataTemplate(this)) {
            const form = $(this);
            const url = form.attr('action');

            Swal.fire({
                title: 'Are you sure?',
                html: "Form will be submitted!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, Confirm!',
                reverseButtons: true,
                customClass: {
                    container: 'swal2-customCss'
                },
            }).then(
                async (result) => {
                    if (result.isConfirmed) {
                        const res = await submitApi(url, form.serializeArray(), 'formTemplate', null, false);
                        if (isSuccess(res)) {
                            if (isSuccess(res.data.code)) {
                                noti(res.status, res.data.message);
                                getDataList();
                                closeModal('#generalModal-fullscreen');
                            } else {
                                noti(400, res.data.message)
                            }
                        }
                    }
                })
        } else {
            validationJsError('toastr', 'single'); // single or multi
        }
    });

    function validateDataTemplate(formObj) {

        const rules = {
            'email_type': 'required|min_length:5|max_length:255',
            'email_subject': 'required|min_length:5|max_length:255',
            'email_body': 'required|min_length:5',
            'email_footer': 'max_length:255',
            'email_cc': 'min_length:5|max_length:255',
            'email_bcc': 'min_length:5|max_length:255',
            'email_status': 'required|integer|in:0,1',
            'id': 'required|integer',
        };
        
        const message = {
            'email_type': { label: 'Type' },
            'email_subject': { label: 'Subject' },
            'email_body': { label: 'Description' },
            'email_footer': { label: 'Footer' },
            'email_cc': { label: 'CC' },
            'email_bcc': { label: 'BCC' },
            'email_status': { label: 'Status' },
            'id': { label: 'Template ID' }
        };

        return validationJs(formObj, rules, message);
    }
</script>