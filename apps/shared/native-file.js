// System PDF import keeps private contracts in the application's document folder.
export function choosePdf() {
  if (plus.os.name === "iOS") return chooseIosPdf();
  return new Promise((resolve, reject) => {
    const activity = plus.android.runtimeMainActivity();
    plus.android.importClass(activity);
    const Intent = plus.android.importClass("android.content.Intent");
    const requestCode = 45201;
    const previous = activity.onActivityResult;
    activity.onActivityResult = (code, result, data) => {
      if (code !== requestCode) {
        if (typeof previous === "function")
          previous.call(activity, code, result, data);
        return;
      }
      activity.onActivityResult = previous;
      if (result !== -1 || !data) {
        reject(new Error("已取消文件选择"));
        return;
      }
      let input,
        output,
        target,
        complete = false;
      try {
        plus.android.importClass(data);
        const uri = data.getData();
        const resolver = activity.getContentResolver();
        plus.android.importClass(resolver);
        input = resolver.openInputStream(uri);
        if (!input) throw new Error("无法读取选中的PDF");
        plus.android.importClass(input);
        const FileOutputStream = plus.android.importClass(
          "java.io.FileOutputStream",
        );
        target = plus.io.convertLocalFileSystemURL(
          `_doc/contract-${Date.now()}.pdf`,
        );
        output = new FileOutputStream(target);
        const Arrays = plus.android.importClass("java.lang.reflect.Array");
        const Byte = plus.android.importClass("java.lang.Byte");
        const bytes = Arrays.newInstance(Byte.TYPE, 8192);
        if (!bytes) throw new Error("系统无法创建文件读取缓冲区");
        let count,
          total = 0;
        while ((count = input.read(bytes)) !== -1) {
          if (!Number.isInteger(count) || count < 1 || count > 8192)
            throw new Error("系统未正确返回PDF读取结果");
          total += count;
          if (total > 50 * 1024 * 1024) throw new Error("PDF不能超过50MB");
          output.write(bytes, 0, count);
        }
        if (!total) throw new Error("选中的PDF为空");
        complete = true;
        resolve(target);
      } catch (error) {
        reject(new Error(`PDF导入失败：${error.message || error}`));
      } finally {
        try {
          output?.close();
        } catch {}
        try {
          input?.close();
        } catch {}
        if (!complete && target) {
          try {
            const File = plus.android.importClass("java.io.File");
            new File(target).delete();
          } catch {}
        }
      }
    };
    try {
      const intent = new Intent(Intent.ACTION_OPEN_DOCUMENT);
      intent.setType("application/pdf");
      intent.addCategory(Intent.CATEGORY_OPENABLE);
      activity.startActivityForResult(intent, requestCode);
    } catch (error) {
      activity.onActivityResult = previous;
      reject(new Error(`无法打开PDF选择器：${error.message || error}`));
    }
  });
}

let iosPicker = null;
let iosDelegate = null;
function chooseIosPdf() {
  if (iosPicker) return Promise.reject(new Error("PDF选择器已经打开"));
  return new Promise((resolve, reject) => {
    try {
      const invoke = (...args) => plus.ios.invoke(...args);
      const Picker = plus.ios.importClass("UIDocumentPickerViewController");
      if (!Picker) throw new Error("系统不支持PDF文档导入");
      const types = invoke("NSArray", "arrayWithObject:", "com.adobe.pdf");
      iosPicker = invoke(Picker, "alloc");
      iosPicker = invoke(iosPicker, "initWithDocumentTypes:inMode:", types, 0);
      iosDelegate = plus.ios.implements("UIDocumentPickerDelegate", {
        "documentPicker:didPickDocumentsAtURLs:": (controller, urls) => {
          try {
            const url = invoke(urls, "objectAtIndex:", 0);
            const source = invoke(url, "path");
            const target = plus.io.convertLocalFileSystemURL(
              `_doc/contract-${Date.now()}.pdf`,
            );
            const manager = invoke("NSFileManager", "defaultManager");
            const copied = invoke(
              manager,
              "copyItemAtPath:toPath:error:",
              source,
              target,
              null,
            );
            if (!copied) throw new Error("无法复制选中的PDF文档");
            resolve(target);
          } catch (e) {
            reject(new Error(`PDF导入失败：${e.message || e}`));
          } finally {
            cleanupIos(controller);
          }
        },
        "documentPicker:didPickDocumentAtURL:": (controller, url) => {
          try {
            const target = plus.io.convertLocalFileSystemURL(
              `_doc/contract-${Date.now()}.pdf`,
            );
            const source = invoke(url, "path");
            const manager = invoke("NSFileManager", "defaultManager");
            if (
              !invoke(
                manager,
                "copyItemAtPath:toPath:error:",
                source,
                target,
                null,
              )
            )
              throw new Error("无法复制选中的PDF文档");
            resolve(target);
          } catch (e) {
            reject(new Error(`PDF导入失败：${e.message || e}`));
          } finally {
            cleanupIos(controller);
          }
        },
        "documentPickerWasCancelled:": (controller) => {
          cleanupIos(controller);
          reject(new Error("已取消文件选择"));
        },
      });
      invoke(iosPicker, "setDelegate:", iosDelegate);
      invoke(iosPicker, "setAllowsMultipleSelection:", false);
      const app = invoke("UIApplication", "sharedApplication");
      const window = invoke(app, "keyWindow");
      let presenter = invoke(window, "rootViewController");
      if (!presenter) throw new Error("当前系统窗口尚未准备好，请返回页面重试");
      let next;
      while ((next = invoke(presenter, "presentedViewController")))
        presenter = next;
      invoke(
        presenter,
        "presentViewController:animated:completion:",
        iosPicker,
        true,
        null,
      );
    } catch (e) {
      cleanupIos();
      reject(new Error(`PDF导入不可用：${e.message || e}`));
    }
  });
}
function cleanupIos(controller) {
  if (controller)
    plus.ios.invoke(
      controller,
      "dismissViewControllerAnimated:completion:",
      true,
      null,
    );
  if (iosPicker) {
    plus.ios.invoke(iosPicker, "setDelegate:", null);
    plus.ios.deleteObject(iosPicker);
  }
  if (iosDelegate) plus.ios.deleteObject(iosDelegate);
  iosPicker = null;
  iosDelegate = null;
}
