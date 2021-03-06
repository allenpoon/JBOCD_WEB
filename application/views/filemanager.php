			<link rel="stylesheet" href="<?php echo asset_url(); ?>css/font-awesome-animation.min.css">
			<link href="<?php echo asset_url(); ?>css/filedrop.css" rel="stylesheet">
			<link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css">
			<style type="text/css">
				tr td:first-child {
			        text-align: center;
			    }
			 
			    tr td:first-child:before {
			        content: "\f096 "; /* fa-square-o */
			        font-family: FontAwesome;
			    }
			 
			    tr.selected td:first-child:before {
			        content: "\f046 "; /* fa-check-square-o */
			    }
			</style>


			<div class="row">
                <div class="span12">
                    <h1 class="header">File Manager</h1>
                </div>
            </div>

            <div class="row" id="fileControl">
            	<button class="primary" id="upload">Upload</button>
	            <button class="primary">New folder</button>
	            <button class="danger" id="delete">Delete</button>
            </div>

            <div class="row">
            	<table class="hovered table" id="fileTable"></table>
            </div>

            <script src="<?php echo asset_url(); ?>js/jquery/jquery.dataTables.js"></script>
            <script src="<?php echo asset_url(); ?>js/dataTables.tableTools.js"></script>
			<script src="<?php echo asset_url(); ?>js/metro/metro-input-control.js"></script>
			<script src="<?php echo asset_url(); ?>js/filedrop.js"></script>
			<script type="text/javascript">
				$('#fileControl').hide();
				fd.jQuery();
				var zone;
//				var workers = [];
				var dt;

				$(document).ready(function() {
					var data = [];

				    dt = $('#fileTable').dataTable( {
				        "data": data,
				        "oLanguage": {
							"sEmptyTable": "No file"
						},
						"columnDefs": [
			            {
			                "targets": [ 3 ],
			                "visible": false,
			                "searchable": false
			            },{
			            	"render": function(data,type,row){
			            		return '<a href="#" class="downloadBtn" data-value="' + row[3] + '" data-is-folder="' + row[4] + '">' + data + '</a>';
			            	},
			            	"targets": 1
			            }],
				        "columns": [
				        	{ data: null, defaultContent: '', orderable: false },
				            { "title": "Filename", "className":"fn" },
				            { "title": "Size" },
				        	{ "title": "id" , "className":"fid"}
				        ],
				        dom: 'T<"clear">lfrtip',
				        tableTools: {
				            "sRowSelect": "os",
				            sRowSelector: 'td:first-child',
				            "aButtons": [{"sExtends": "csv","sButtonText": ""}]
				        },
        				"order": [[1, 'asc']]
				    } );
				});

				$("#upload").on('click', function(){
				    $.Dialog({
				    	padding: 20,
				    	width: "30%",
				    	height: "40%",
				        overlay: true,
				        shadow: true,
				        flat: true,
				        title: 'Upload',
				        content: '',
				        onShow: function(_dialog){
				        	var content = _dialog.children('.content');
				        	content.html('<div class="container" id="holder"><h1 class="text-center" id="uploadbox"><i class="fa fa-hand-o-up"></i><span> CLICK <small>or</small> DROP </span><i class="fa fa-upload"></i></h1></div>');
				        	$('#holder')
				        		.filedrop({multiple: true})
				        		.on('fdsend', function(e, files){
				        			readfiles(files);
				        		});
				        }
				    });
				});
			</script>
			<script type="text/javascript">
				var uid = <?php echo $uid; ?>;
				var ldid = <?php echo $ldid; ?>;
				var CSRF = '<?php echo $CSRF; ?>';
				var script = '<?php echo $algo; ?>';
				var files = [];
				var dir = 0;
				var drives;
				var numOfDrive;
				var workerTemp = {};
//				var fileTemp = {};
				var allChunks = 0, totalChunks = 0;

				var fileDownload = (function(){
					return function(e){
						//console.log("Download file: ", this.innerHTML," id=", this.getAttribute("data-value"));
						if(this.dataset.isFolder == "true"){ // data-is-folder
							JBOCD.Socket.list(ldid, this.dataset.value, refreshFilelist);
						}else{
							var worker = new Worker('<?php echo asset_url(); ?>algo/worker.js');
							worker.onmessage = function(e){
								switch(e.data[0]){
									case "getMinAcceptChunk":
										minAcceptChunk = e.data[1];
										break;
									case "decode":
										if(e.data[2]){
											decodedRow++;
											decodedChunkList[e.data[1]] = e.data[2];
											if(decodedRow == numOfRow){
												FILE_DONE_LA = decodedChunkList;
												console.log("Fin GET", decodedChunkList);
											}else{
												console.log("Not Yet OK", decodedRow, numOfRow);
											}
										}
										break;
								}
							}
							worker.postMessage([script, [numOfDrive, 1024*1024], null, ["getMinAcceptChunk"]]);

							var decodedChunkList = [];
							var decodedRow = 0;
							var numOfRow = 0;

							var minAcceptChunk = Infinity; // maxLostChunk == numOfDrive - minAcceptChunk
							// var verifyList = []; // for rebuild lost chunk
							var getFileHandler = function(e){
								if(minAcceptChunk < Infinity){
									if(!numOfRow) numOfRow = Math.ceil(e.response.numOfChunk / numOfDrive);
									while(e.response.seqQueue && e.response.seqQueue.length){
										var row = Math.floor(e.response.seqQueue.shift() / numOfDrive);
										var counter = 0;
										var isLast = (row == numOfRow - 1);
										for(var i=0; i<numOfDrive; i++){
											if(e.response.chunkList[row * numOfDrive + i] && e.response.chunkList[row * numOfDrive + i].isEnd){
												counter++;
											}
										}
										if(
											!decodedChunkList[row]
											&&
											(
												!isLast && counter >= minAcceptChunk
												||
												isLast && numOfDrive - minAcceptChunk >= e.response.numOfChunk % numOfDrive - counter
											)
										){
											var chunkList = [];
											var chunkSize = [];
											var ref;
											for(var i=0; i< numOfDrive; i++){
												ref = e.response.chunkList[row*numOfDrive+i];
												if(ref){
													if(ref.isEnd && !ref.isError){
														if(!ref.blob){
															var tmp = [];
															for(var j=0; j<ref.blobList.length; j++){
																tmp.push(ref.blobList[j].blob);
															}
															ref.blob = new Blob(tmp);
														}
													}
													chunkList.push(ref.blob);
													chunkSize.push(ref.size);
												}else{
													chunkList.push(null);
													chunkSize.push(-1);
												}
											}
											worker.postMessage([
												script,
												[numOfDrive, 1024*1024],
												chunkList,
												["decode", row, chunkSize]
											]);
										}
									}
								}else{
									setTimeout(getFileHandler, 100, (function(a){var b=a;return b;})(e));
								}
							};

							JBOCD.Socket.getFile(ldid, Number(this.dataset.value), getFileHandler);
						}
					};
				})();

				//File array = [name, size]
				var refreshFilelist = (function(){
					var parentStack = [];
					return function(e){
						files = [];
						var fileList = e.response.fileList;
						//console.log(fileList);
						for(var i = 0; i < fileList.length; i++){
							var size = fileList[i].size;
							var unit;
							if(fileList[i].size < 1024){ unit = " bytes"; }
							else if(fileList[i].size < 1048576){
								unit = " KB";
								size /= 1024;
							}else if(fileList[i].size < 1073741824){
								unit = " MB";
								size /= 1048576;
							}else if(fileList[i].size < 1099511627776){
								unit = " GB";
								size /= 1073741824;
							}
							files[i] = [
								null,
								decodeURIComponent(fileList[i].name),
								fileList[i].size == 0 ? "" : parseFloat(Math.round(size * 100) / 100).toFixed(2) + unit,
								fileList[i].fID,
								fileList[i].size == 0
							];
						}
						// handling go back operation
						if(parentStack.length >= 2 && e.request.parentID == parentStack[parentStack.length-2]){
							parentStack.pop();
						}else{
							parentStack.push(e.request.parentID);
						}
						if(parentStack.length >= 2){
							files.splice(0,0,[
								null,
								"↰ Go Back",
								"",
								parentStack[parentStack.length-2],
								true
							]);
						}
						// handling go back operation end

						$('.downloadBtn').off('click', fileDownload);
						dt.fnClearTable();
						if(files.length > 0){ 
							dt.fnAddData( files );
							for(var i = 0; i < files.length; i++){
								dt.fnSettings().aoData[i].nTr.value = files[i][3];
							}
							$('.downloadBtn').on('click', fileDownload);
						}
					}
				})();

				var workerCollection = function(id){
					//if(fileTemp[id])
				}

				var readfiles = function(files) {
					$("#uploadbox").html('<i class="fa fa-circle-o-notch fa-spin"></i> Uploading ... <span id="percent">0%</span>');
					for (var i = 0; i < files.length; i++) {
						var file = files[i];
						var fileSize = files[i].size;
						JBOCD.Socket.createFile(ldid, dir, files[i].size, encodeURIComponent(files[i].name), (function(){
							var chunkList = [];
							var totalNumOfChunk = 0;
							var numOfChunkDone = 0;
							var numOfChunkUploaded = 0;

							var fID = 0;
							var worker = null;
							// var ldID = ldid; // ldid is global variable


							var putChunkCB = function(e){
								if(e.response.status == 251){ // retry limit exceed, resent package
									chunkList.push({
										cdID: e.request.cdID,
										seqNum: e.request.seqNum,
										blob: e.request.blob
									});
								}
								console.log("Fin Put chunk:", e);
								numOfChunkUploaded += 1;
								allChunks++;
								$("#percent").html((allChunks/totalChunks*100) + "%");

								if(chunkList.length > 0){
									putChunk();
								}
								if(numOfChunkUploaded == totalNumOfChunk){
//										allChunks+= fileTemp[fID].totalNumOfChunks;
//										$("#percent").html((allChunks/totalChunks*100) + "%");
									if(allChunks == totalChunks){
										JBOCD.Socket.list(ldid, dir, refreshFilelist);
										$(".btn-close").click();
										allChunks=0;
										totalChunks=0;
									}
								}
							};

							var putChunk = function(){
								var opID = 0;

								while(opID >= 0 && chunkList.length > 0){
									var chunk = chunkList.shift();
									opID = JBOCD.Socket.putChunk(ldid, chunk.cdID, fID, chunk.seqNum, '', chunk.blob, putChunkCB);
									if(opID < 0){
										chunkList.splice(0,0,chunk);
									}
								};
								/*
								JBOCD.Socket.putChunk(ldid, drives[e.data[3]].cdID, e.data[1], e.data[2], '', e.data[0], function(e){
									console.log("Fin Put chunk:", e);
									fileTemp[e.request.fID].completedChunks += 1;
									if(fileTemp[e.request.fID].completedChunks == fileTemp[e.request.fID].totalNumOfChunks){
										workers[e.request.fID].postMessage("close");
										allChunks+= fileTemp[e.request.fID].totalNumOfChunks;
										$("#percent").html((allChunks/totalChunks*100) + "%");
										delete workers[e.request.fID];
										delete fileTemp[e.request.fID];
										if(allChunks == totalChunks){
											JBOCD.Socket.list(ldid, dir, refreshFilelist);
											$(".btn-close").click();
											allChunks=0;
											totalChunks=0;
										}
									}
								});
								*/
							};

							// load chunk
							worker = new Worker('<?php echo asset_url(); ?>algo/worker.js');

							//console.log("POST:", [script, [numOfDrive, 1024*1024], file.nativeFile, ['encode', e.response.fID]]);
							worker.onmessage = function(e){
								//console.log("PutChunk:", [ldid, drives[e.data[3]].cdID, e.data[1], e.data[2], '', e.data[0]]);
								if(numOfChunkDone == 0){
									totalNumOfChunk = e.data[5];
									totalChunks+=e.data[5];
								}
								chunkList.push({
									cdID: drives[e.data[3]].cdID,
									seqNum: e.data[2],
									blob: e.data[0]
								});
								numOfChunkDone++;
								if(numOfChunkDone == totalNumOfChunk){
									worker.postMessage("close");
									delete worker;
								}
								if(fID > 0){
									putChunk();
								}
							}
							worker.postMessage([script, [numOfDrive, 1024*1024], file.nativeFile, ['encode', 0]]);

							return function(e){
								fID = e.response.fID;
								console.log("res",e);
								if(chunkList.length > 0){
									putChunk();
								}
							};
						})());
					}
				};

				var getFileCallback = function(e){
					//var data = 
				}

				$("#delete").on('click', function(){
					var delFileList = $(".DTTT_selected");
					var delFileNameList = $(".DTTT_selected .fn");
					if(delFileList.length > 0){
						var str = '';
						for(var i = 0; i < delFileNameList.length;i++){
							str += '<li>'+delFileNameList[i].innerHTML+'</li>';
						}
					    $.Dialog({
					    	padding: 20,
					    	width: "30%",
					    	height: "40%",
					        overlay: true,
					        shadow: true,
					        flat: true,
					        title: 'Delete',
					        content: '',
					        onShow: function(_dialog){
					        	var content = _dialog.children('.content');
					        	content.html('<div class="container" id="delBox"><h3>Are you sure to delete following files?</h3><ul>'+str+'</ul><button class="danger large" id="deleteConfirm">Delete</button><button class="large" id="deleteCancel">Cancel</button></div>');
					        	$("#deleteConfirm").on('click', function(){
									var delFileList = $(".DTTT_selected");
									totalChunks = delFileList.length;
									for(var i = 0; i < delFileList.length;i++){
										JBOCD.Socket.delFile(ldid, parseInt(delFileList[i].value));
										//console.log("Del:FID=", parseInt(delFileList[i].value));
									}
									$("#delBox").html('<h1 class="text-center"><i class="fa fa-warning faa-flash animated"></i> '+(allChunks/totalChunks*100) + "%"+'</h1>');
									$("#deleteConfirm").off();
									$("#deleteCancel").off();
								});

								$("#deleteCancel").on('click', function(){
									$('.btn-close').click();
									$("#deleteConfirm").off();
									$("#deleteCancel").off();
								});
					        }
					    });
					}
				});

				


				var delFileCallback = function(e){
					allChunks++;
					$("#delBox").html('<h1 class="text-center"><i class="fa fa-warning faa-flash animated"></i> '+(allChunks/totalChunks*100) + "%"+'</h1>');
					if(allChunks==totalChunks){
						JBOCD.Socket.list(ldid, dir, refreshFilelist);
						$('.btn-close').click();
						allChunks=0;
						totalChunks=0;
					}
				};

				JBOCD.Socket.init(function(e){
					console.log("JBOCD connected!");
					var loginOp = JBOCD.Socket.login(uid, CSRF, function(e){
						console.log("JBOCD authenticated!");
						JBOCD.Socket.getLogicalDrive(function(e){
							for(var i = 0; i < e.response.ldList.length; i++){
								console.log("CHK", e.response.ldList[i]);
								if(e.response.ldList[i].ldID == ldid){
									drives = e.response.ldList[i].cdList;
									numOfDrive = e.response.ldList[i].cdList.length;
									break;
								}
							}
							JBOCD.Socket.list(ldid, dir, refreshFilelist);
							JBOCD.Socket.setDelFileCallback(delFileCallback);
							$('#fileControl').show();
						});
					});
				});

			</script>
			
