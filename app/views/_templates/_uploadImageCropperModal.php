<div class="row">
	<form id="changePictureUpload" method="POST" action="controllers/UploadController.php">
		<div class="col-12">
			<input id="change_image" type="file" name="change_image" class="form-control mb-4" accept="image/x-png,image/jpeg,image/jpg">

			<div style="position: relative; display:inline-block;">
				<div id="resizer" class="mt-2"></div>
				<center>
					<button type="button" class="btn btn-sm btn-outline-info rotate float-left" data-deg="90" id="undoBtn" style="display: none;">
						<i class="bx bx-rotate-left"></i>
					</button>
					<button type="button" class="btn btn-sm btn-outline-info rotate float-right" data-deg="-90" id="redoBtn" style="display: none;">
						<i class="bx bx-rotate-right"></i>
					</button>
				</center>

				<div class="row mt-4 mb-4">
					<div class="col-6 col-lg-6 col-sm-6">
						<img id="previewSquare" src="" style="max-width: 100%;" class="img-fluid" loading="lazy">
					</div>
					<div class="col-6 col-lg-6 col-sm-6">
						<img id="previewCircle" src="" style="max-width: 100%;" class="rounded-circle img-fluid" loading="lazy">
					</div>
				</div>

			</div>
			<hr>

			<div class="alert alert-warning" role="alert">
				<span class="form-text text-muted"><b> A few notes before you upload a new profile picture </b></span>
				<span class="form-text text-muted">
					<ul>
						<li> Upload only file with extension jpeg and png. </li>
						<li> Files size support only <b><i style="color: red"> 8 MB. </i> </b></li>
						<li> Please wait for the upload to complete. </li>
					</ul>
				</span>
			</div>

			<center>
				<div id="uploadPhotoProgressBar" class="mb-3"></div>
				<input type="hidden" name="image" id="image64" placeholder="image crop result">
				<input type="hidden" name="id" id="id_upload" placeholder="id_upload">
				<input type="hidden" name="entity_id" id="entity_id" placeholder="entity_id">
				<input type="hidden" name="entity_type" id="entity_type" placeholder="entity_type">
				<input type="hidden" name="entity_file_type" id="entity_file_type" placeholder="entity_file_type">
				<input type="hidden" name="action" value="uploadImageCropper" readonly>
				<input type="hidden" name="folder_group" id="folder_group" placeholder="folder_group" readonly>
				<input type="hidden" name="folder_type" id="folder_type" placeholder="folder_type" readonly>
				<input type="hidden" name="_whitelist_field" value="image" readonly>
				<button type="button" id="deleteBtn" class="btn btn-outline-danger d-none">
					<i class="bx bx-trash me-1"></i>
					Delete
				</button>
				<button type="submit" id="uploadBtn" class="btn btn-info">
					<i class="bx bx-upload me-1"></i>
					Upload
				</button>
			</center>
		</div>
	</form>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.css" integrity="sha512-zxBiDORGDEAYDdKLuYU9X/JaJo/DPzE42UubfBw9yg8Qvb2YRRIQ8v4KsGHOx2H1/+sdSXyXxLXv5r7tHc9ygg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<script src="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.js" integrity="sha512-Gs+PsXsGkmr+15rqObPJbenQ2wB3qYvTHuJO6YJzPe/dTLvhy0fmae2BcnaozxDo5iaF8emzmCZWbQ1XXiX2Ig==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<script>
	var croppie = null;
	var reloadFunction = null;

	function getPassData(baseUrl, data) {
		$('#id_upload').val(data.id);
		$('#entity_id').val(data.entity_id);
		$('#entity_type').val(data.entity_type);
		$('#entity_file_type').val(data.entity_file_type);

		$('#folder_group').val(data.folder_group);
		$('#folder_type').val(data.folder_type);

		$('#change_image').val('');
		$('#image64').val('');
		$('#resizer').empty();
		$("#uploadBtn").attr('disabled', true);
		$('#undoBtn').hide();
		$('#redoBtn').hide();

		$('#changePictureUpload').attr('action', data.url);

		// reloadFunction = data.reloadFunction;
		$('#deleteBtn').addClass('d-none');

		if (!empty(data.id)) {
			$('#deleteBtn').removeClass('d-none');
			document.getElementById('deleteBtn').onclick = function() {
				removeFilesUpload(data.id);
			};
		}

		var cropperConfig = data.cropperConfig || {};
		if (!empty(data.imagePath)) {
			var imageUrl = asset(data.imagePath, false);

			setTimeout(function() {
				initializeCropper(cropperConfig);
				$.getImage(imageUrl, croppie);
				$("#uploadBtn").attr('disabled', false);
				$('#undoBtn').show();
				$('#redoBtn').show();
			}, 50);
		}
	}

	function destroyCropper() {
		if (croppie) {
			try {
				croppie.destroy();
				console.log('Cropper destroyed successfully');
			} catch (e) {
				console.log('Error destroying cropper:', e);
			}
			croppie = null;
		}
	}

	function initializeCropper(config = {}) {
		destroyCropper();
		var el = document.getElementById('resizer');

		// Set defaults and override with config
		var viewportWidth = config.viewportWidth || 250;
		var viewportHeight = config.viewportHeight || 250;
		var boundaryWidth = config.boundaryWidth || 350;
		var boundaryHeight = config.boundaryHeight || 350;
		var type = config.type || 'square';

		croppie = new Croppie(el, {
			viewport: {
				width: viewportWidth,
				height: viewportHeight,
				type: type
			},
			boundary: {
				width: boundaryWidth,
				height: boundaryHeight
			},

			// // resize controls
			// resizeControls: {
			//     width: true,
			//     height: true
			// },

			// // enable image resize
			enableResize: false,

			// // show image zoom control
			// showZoomer: true,

			// // image zoom with mouse wheel
			// mouseWheelZoom: false,

			// enable exif orientation reading
			enableExif: false,

			// restrict zoom so image cannot be smaller than viewport
			enforceBoundary: true,

			// enable orientation
			enableOrientation: true,

			// enable key movement
			// enableKeyMovement: true,
		});
	}

	// upload image
	$(function() {
		$.getImage = function(input, croppie) {
			if (typeof input === "string") {
				// input is a file path string
				croppie.bind({
					url: input,
				});
				setBase64();
			} else if (input.files && input.files[0]) {
				// input is a file input element
				var reader = new FileReader();
				reader.onload = function(e) {
					croppie.bind({
						url: e.target.result,
					});
					setBase64();
				}
				reader.readAsDataURL(input.files[0]);
			}
		}

		$("#change_image").on("change", function(event) {
			if (validateUploadData()) {
				$('#filename').val(this.files[0].name); // this will clear the input val.
				$('#undoBtn').show();
				$('#redoBtn').show();
				// Initailize croppie instance and assign it to global variable
				initializeCropper();
				$.getImage(event.target, croppie);
				$("#uploadBtn").attr('disabled', false);
			} else {
				destroyCropper();
				validationJsError('toastr', 'single'); // single or multi
				$("#uploadBtn").attr('disabled', true);
				$('#change_image').val(''); // this will clear the input val.
				$('#resizer').empty();
				$("#uploadBtn").attr('disabled', true);
				$('#undoBtn').hide();
				$('#redoBtn').hide();
			}
		});

		$('#resizer').on('update.croppie', function(ev, cropData) {
			setBase64();
		});

		// To Rotate Image Left or Right
		$(".rotate").on("click", function() {
			croppie.rotate(parseInt($(this).data('deg')));
			setBase64();
		});

		$('#generaloffcanvas-right').on('hidden.bs.modal', function(e) {
			$('#image').val(''); // this will clear the input val.
			$('#undoBtn').hide();
			$('#redoBtn').hide();
			$('#resizer').empty();
			$("#uploadBtn").attr('disabled', true);
			$('#image64').val(''); // this will clear the input val.
			// This function will call immediately after model close
			// To ensure that old croppie instance is destroyed on every model close
			setTimeout(function() {
				destroyCropper();
			}, 80);
		});

		$("#changePictureUpload").submit(function(event) {

			event.preventDefault();

			const form = $(this);
			const url = form.attr('action');

			Swal.fire({
				title: 'Are you sure?',
				html: "Photo will be upload!",
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
						croppie.result('base64').then(async function(base64) {

							$('#image64').val(base64);

							const submitBtnText = $('#uploadBtn').html();
							loadingBtn('uploadBtn', true); // block button from submit
							const res = await uploadApi(url, 'changePictureUpload', 'uploadPhotoProgressBar', reloadFunction);

							if (isSuccess(res)) {
								const result = res.data;
								closeOffcanvas('#generaloffcanvas-right');
								noti(res.status, 'Image uploaded');
							} else {
								noti(res.status);
							}

							loadingBtn('uploadBtn', false, submitBtnText); // unblock button from submit
						});
					}
				});

		});

	});

	function setBase64() {
		croppie.result(
			'base64',
			'viewport',
			'jpeg',
			1,
			false
		).then(function(base64) {
			$('#previewSquare').attr('src', base64);
			$('#previewCircle').attr('src', base64);
		});
	}

	function validateUploadData() {

		const rules = {
			'change_image': 'required|file|image|size:8|mimes:jpg,jpeg,png',
			// 'change_image': 'required_if:image,=,""|file|size:8|mimes:jpg,jpeg,png',
		};
		
		const message = {
            'change_image': {
                label: 'Upload File'
            }
        };

		return validationJs('changePictureUpload', rules, message);
	}

	function removeFilesUpload(id) {
		Swal.fire({
			title: 'Are you sure?',
			html: 'You won\'t be able to revert this action!<br><strong>This item will be permanently deleted.</strong>',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonColor: '#3085d6',
			cancelButtonColor: '#d33',
			confirmButtonText: 'Yes, Remove it!',
			reverseButtons: true,
			customClass: {
				container: 'swal2-customCss'
			},
		}).then(async (result) => {
			if (result.isConfirmed) {
				const res = await callApi('post', "controllers/UploadController.php", {
					'action': 'removeUploadFiles',
					'id': id
				});

				if (isSuccess(res)) {
					const response = res.data;
					noti(response.code, response.message);
					closeOffcanvas('#generaloffcanvas-right');
					getDataList(); // reload
				}
			}
		})
	}
</script>